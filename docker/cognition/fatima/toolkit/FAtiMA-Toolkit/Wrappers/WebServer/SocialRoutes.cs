using GAIPS.Rage;
using IntegratedAuthoringTool;
using Newtonsoft.Json;
using Newtonsoft.Json.Linq;
using RolePlayCharacter;
using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Text.RegularExpressions;
using WellFormedNames;

namespace WebServer
{
    // Added by OGameX: the social routes of SOCIAL-ENGINES-CONTRACT.md, POST /social/appraise and
    // POST /social/exchange, batched as {"items":[...]} -> {"results":[...]}.
    //
    // This file only moves data in and out of the toolkit. Every judgement is FAtiMA's, evaluated over the
    // authored scenario bundle scenarios/ogame-social.json (Applications/SocialSeeder): OCC appraisal rules,
    // emotion dispositions and decay, mood, goals, decision rules that move relationship beliefs, Comme il
    // Faut exchange volitions and social importance.
    //
    // Stateless: a character's living state (mood, active emotions with their cause and age, goal
    // likelihoods, relationships per player, recent memories) travels in the request and the updated state
    // is returned. Nothing is kept between requests, so any replica answers any request.
    public static class SocialRoutes
    {
        const int MemoryLimit = 32;
        const int MaxAdvance = 5000;

        class Persona
        {
            public string Key;
            public string Character;
            public AssetStorage Assets;
            public JObject Scenario;
            public Dictionary<string, float> Goals;
            public float[] Traits;
        }

        static JObject bundle;
        static readonly Dictionary<string, Persona> personas = new Dictionary<string, Persona>();
        static string[] traitNames;
        static string[] relationNames;
        static Dictionary<string, string> eventActions;
        static Dictionary<string, string> actionEvents;
        static string[] exchangeNames;
        static string recordClassName;

        // ------------------------------------------------------------------ entry points

        public static string HandleAppraise(APIRequest req, ServerState serverState) => Batch(req, Appraise);

        public static string HandleExchange(APIRequest req, ServerState serverState) => Batch(req, Exchange);

        static string Batch(APIRequest req, Func<JObject, JObject> one)
        {
            if (req.Method != HTTPMethod.POST)
                return JsonConvert.SerializeObject(APIErrors.ERROR_INVALID_HTTP_METHOD);

            JObject body;
            try
            {
                EnsureBundle();
                body = JObject.Parse(string.IsNullOrEmpty(req.RequestBody) ? "{}" : req.RequestBody);
            }
            catch (Exception ex)
            {
                return JsonConvert.SerializeObject(new { error = ex.Message });
            }

            var items = body["items"] as JArray;
            if (items == null)
                return JsonConvert.SerializeObject(new { error = "A social request body is {\"items\":[...]}." });

            var results = new JArray();
            foreach (var item in items)
            {
                try
                {
                    results.Add(one(item as JObject ?? throw new ArgumentException("Each item must be an object.")));
                }
                catch (Exception ex)
                {
                    results.Add(new JObject { ["error"] = ex.Message });
                }
            }
            return new JObject { ["results"] = results }.ToString(Formatting.None);
        }

        // ------------------------------------------------------------------ /social/appraise

        static JObject Appraise(JObject item)
        {
            var s = Session.Open(item);
            var newIds = s.PerceiveAll(Events(item));
            s.AdvanceTo(item.Value<long?>("now"));

            var felt = s.Rpc.GetAllActiveEmotions().Where(e => newIds.Contains(e.CauseEventId)).ToList();
            var emotions = new JObject();
            foreach (var g in felt.GroupBy(e => e.Type))
                emotions[g.Key] = Round(g.Max(e => e.Intensity));
            var dominant = felt.OrderByDescending(e => e.Intensity).FirstOrDefault();

            var state = s.Export();
            return new JObject
            {
                ["emotions"] = emotions,
                ["dominant"] = dominant?.Type,
                ["mood"] = state["mood"],
                ["relations"] = state["relations"],
                ["state"] = state,
            };
        }

        // Events of an item: either the contract's single {event, actor_relation, intensity} or "events": [...].
        static List<JObject> Events(JObject item)
        {
            var list = new List<JObject>();
            if (item["events"] is JArray arr)
                list.AddRange(arr.OfType<JObject>());
            else if (item["event"] != null)
                list.Add(new JObject
                {
                    ["event"] = item["event"],
                    ["actor"] = item["actor"] ?? item["other"] ?? "other",
                    ["intensity"] = item["intensity"] ?? 0.5,
                    ["actor_relation"] = item["actor_relation"],
                    ["tick"] = item["tick"],
                });
            return list;
        }

        // ------------------------------------------------------------------ /social/exchange

        static JObject Exchange(JObject item)
        {
            var other = item.Value<string>("other") ?? throw new ArgumentException("'other' is required.");
            var s = Session.Open(item);
            s.PerceiveAll(Events(item));
            s.AdvanceTo(item.Value<long?>("now"));

            var alias = s.Alias(other);
            if (item["relation"] is JObject rel)
                s.SetRelation(alias, rel);
            s.SetContext(alias, item["context"] as JObject);
            s.Rpc.UpdateBelief("Focus(SELF)", alias);
            s.Rpc.m_socialImportanceAsset?.InvalidateCachedSI();

            var options = (item["options"] as JArray)?.Select(o => o.ToString()).ToList() ?? exchangeNames.ToList();

            // CiF: the volition of every exchange whose starting condition holds for this player.
            var scores = new JObject();
            foreach (var ev in s.Rpc.m_commeillFautAsset.EvaluateExchanges(Name.BuildName(alias)))
                if (options.Contains(ev.Name) && ev.Volitions.TryGetValue("*", out var v) && !float.IsInfinity(v))
                    scores[ev.Name] = v;

            // FAtiMA decision making (layer Exchange) ranks them; ties keep the caller's option order.
            var ranked = s.Rpc.Decide(Name.BuildName("Exchange"))
                .Select(a => (Name: a.Name.GetNTerm(1).ToString(), a.Utility))
                .Where(a => options.Contains(a.Name))
                .OrderByDescending(a => a.Utility).ThenBy(a => options.IndexOf(a.Name))
                .ToList();

            float si = s.Rpc.m_socialImportanceAsset?.GetSocialImportance(alias) ?? 0;

            return new JObject
            {
                ["choice"] = ranked.Count > 0 ? ranked[0].Name : null,
                ["scores"] = scores,
                ["ranking"] = new JArray(ranked.Select(r => new JObject { ["exchange"] = r.Name, ["utility"] = Round(r.Utility) })),
                ["social_importance"] = si,
                ["state"] = s.Export(),
            };
        }

        // ------------------------------------------------------------------ session: state in, toolkit, state out

        class Session
        {
            public Persona P;
            public IntegratedAuthoringToolAsset Iat;
            public RolePlayCharacterAsset Rpc;
            public long BaseTick;
            readonly Dictionary<string, string> aliasOf = new Dictionary<string, string>();
            readonly Dictionary<string, string> actorOf = new Dictionary<string, string>();
            readonly Dictionary<uint, long> memoryTick = new Dictionary<uint, long>();
            readonly Dictionary<uint, string> memoryEvent = new Dictionary<uint, string>();

            public string Alias(string actor)
            {
                if (!aliasOf.TryGetValue(actor, out var a))
                {
                    a = "P" + (aliasOf.Count + 1);
                    aliasOf[actor] = a;
                    actorOf[a] = actor;
                    if (Rpc != null) EnsureRelation(a);
                }
                return a;
            }

            public string Actor(string alias) => alias != null && actorOf.TryGetValue(alias, out var a) ? a : alias;

            public static Session Open(JObject item)
            {
                var s = new Session { P = ResolvePersona(item["persona"]) };
                var state = item["state"] as JObject ?? new JObject();
                s.BaseTick = state.Value<long?>("tick") ?? 0;

                // Aliases first: FAtiMA names must be well-formed symbols, player names need not be.
                foreach (var p in (state["relations"] as JObject)?.Properties() ?? Enumerable.Empty<JProperty>()) s.Alias(p.Name);
                foreach (var m in (state["memories"] as JArray)?.OfType<JObject>() ?? Enumerable.Empty<JObject>()) s.Alias(m.Value<string>("actor"));
                foreach (var e in (state["emotions"] as JArray)?.OfType<JObject>() ?? Enumerable.Empty<JObject>())
                    if (!string.IsNullOrEmpty(e.Value<string>("target"))) s.Alias(e.Value<string>("target"));

                var scenario = (JObject)s.P.Scenario.DeepClone();
                s.Inject(scenario, state);
                s.Iat = IntegratedAuthoringToolAsset.FromJson(scenario.ToString(Formatting.None), s.P.Assets);
                s.Rpc = s.Iat.Characters.First();

                var traits = ResolveTraits(item["persona"], s.P);
                for (int i = 0; i < traitNames.Length; i++)
                    s.Rpc.UpdateBelief($"Trait(SELF, {Cap(traitNames[i])})", Fmt(traits[i]));

                var goals = state["goals"] as JObject;
                foreach (var g in s.P.Goals)
                    s.Rpc.AddOrUpdateGoal(new EmotionalAppraisal.DTOs.GoalDTO
                    {
                        Name = g.Key + "(SELF)",
                        Significance = g.Value,
                        Likelihood = goals?.Value<float?>(g.Key) ?? 1f,
                    });

                s.Rpc.UpdateBelief("Focus(SELF)", "None");
                foreach (var a in s.actorOf.Keys.ToList()) s.EnsureRelation(a);
                foreach (var p in (state["relations"] as JObject)?.Properties() ?? Enumerable.Empty<JProperty>())
                    if (p.Value is JObject r) s.SetRelation(s.aliasOf[p.Name], r);
                return s;
            }

            // Writes mood, active emotions and their causing memories into the character, in the toolkit's
            // own serialized form. FAtiMA restores an emotion's decay origin at tick 0, so the character's
            // clock restarts at 0 and the world tick is kept here as BaseTick.
            void Inject(JObject scenario, JObject state)
            {
                var ch = (JObject)scenario["root"]["Characters"][0];
                var types = (JArray)scenario["types"];
                int recordClass = types.Count;
                types.Add(new JObject { ["TypeId"] = recordClass, ["ClassName"] = recordClassName });

                var es = (JObject)ch["EmotionalState"];
                es["Mood"] = state.Value<float?>("mood") ?? 0f;
                es["initialTick"] = 0;

                var memories = (state["memories"] as JArray)?.OfType<JObject>().ToList() ?? new List<JObject>();
                var emotions = (state["emotions"] as JArray)?.OfType<JObject>().ToList() ?? new List<JObject>();
                var records = new JArray();
                foreach (var m in memories)
                {
                    var id = m.Value<uint>("id");
                    var action = eventActions.TryGetValue(m.Value<string>("event") ?? "", out var a) ? a : throw new ArgumentException($"Unknown memory event '{m["event"]}'.");
                    memoryTick[id] = m.Value<long?>("tick") ?? BaseTick;
                    memoryEvent[id] = m.Value<string>("event");
                    records.Add(new JObject
                    {
                        ["classId"] = recordClass,
                        ["Id"] = id,
                        ["Type"] = "Action-End",
                        ["Subject"] = aliasOf[m.Value<string>("actor")],
                        ["Timestamp"] = 0,
                        ["LinkedEmotions"] = new JArray(emotions.Where(e => e.Value<uint?>("memory") == id).Select(e => e.Value<string>("type")).Distinct()),
                        ["Action"] = action,
                        ["Target"] = P.Character,
                    });
                }
                ch["AutobiographicMemory"] = new JObject { ["Tick"] = 0, ["records"] = records };

                var pool = new JArray();
                foreach (var e in emotions)
                {
                    var id = e.Value<uint?>("memory") ?? throw new ArgumentException("Each emotion needs its 'memory' id.");
                    var mem = memories.FirstOrDefault(m => m.Value<uint>("id") == id) ?? throw new ArgumentException($"Emotion cause memory {id} is missing.");
                    var native = new JObject
                    {
                        ["Intensity"] = e.Value<float>("intensity"),
                        ["Decay"] = e.Value<int?>("decay") ?? 1,
                        ["Threshold"] = e.Value<int?>("threshold") ?? 0,
                        ["CauseId"] = id,
                    };
                    var target = e.Value<string>("target");
                    if (!string.IsNullOrEmpty(target)) native["Direction"] = aliasOf[target];
                    native["EventName"] = $"Event(Action-End, {aliasOf[mem.Value<string>("actor")]}, {eventActions[mem.Value<string>("event")]}, {P.Character})";
                    if (!string.IsNullOrEmpty(target)) native["Target"] = aliasOf[target];
                    native["EmotionType"] = e.Value<string>("type");
                    native["Valence"] = e.Value<string>("valence") ?? "Negative";
                    native["AppraisalVariables"] = e["variables"] ?? new JArray();
                    native["InfluenceMood"] = e.Value<bool?>("influences_mood") ?? true;
                    pool.Add(native);
                }
                es["EmotionalPool"] = pool;
            }

            void EnsureRelation(string alias)
            {
                foreach (var r in relationNames)
                    if (Missing(Rpc.GetBeliefValue($"{Cap(r)}(SELF, {alias})")))
                        Rpc.UpdateBelief($"{Cap(r)}(SELF, {alias})", Fmt(DefaultRelation(r)));
                foreach (var c in new[] { "Pact", "Offered", "Demand", "Danger" })
                    if (Missing(Rpc.GetBeliefValue($"{c}(SELF, {alias})")))
                        Rpc.UpdateBelief($"{c}(SELF, {alias})", "False");
                if (Missing(Rpc.GetBeliefValue($"Power(SELF, {alias})")))
                    Rpc.UpdateBelief($"Power(SELF, {alias})", "1");
                if (Missing(Rpc.GetBeliefValue($"Magnitude(SELF, {alias})")))
                    Rpc.UpdateBelief($"Magnitude(SELF, {alias})", "0");
            }

            // A stranger: no trust earned yet, no debt; neutral otherwise.
            static float DefaultRelation(string r) => r == "trust" ? 0.3f : r == "debt" ? 0f : r == "fear" ? 0.2f : 0.5f;

            public void SetRelation(string alias, JObject rel)
            {
                foreach (var r in relationNames)
                {
                    var v = rel.Value<float?>(r);
                    if (v.HasValue) Rpc.UpdateBelief($"{Cap(r)}(SELF, {alias})", Fmt(Clamp01(v.Value)));
                }
            }

            // Visible facts about the other player: a public pact, a pact offer, a demand received, an ally
            // under attack, and the public points ratio (theirs / mine).
            public void SetContext(string alias, JObject ctx)
            {
                if (ctx == null) return;
                void B(string key, string belief)
                {
                    var v = ctx.Value<bool?>(key);
                    if (v.HasValue) Rpc.UpdateBelief($"{belief}(SELF, {alias})", v.Value ? "True" : "False");
                }
                B("pact", "Pact");
                B("offered_pact", "Offered");
                B("demand", "Demand");
                B("ally_in_danger", "Danger");
                var power = ctx.Value<float?>("power_ratio");
                if (power.HasValue) Rpc.UpdateBelief($"Power(SELF, {alias})", Fmt(power.Value));
            }

            long Now => BaseTick + (long)Rpc.Tick;

            public void AdvanceTo(long? tick)
            {
                if (!tick.HasValue) return;
                var steps = Math.Min(MaxAdvance, tick.Value - Now);
                for (long i = 0; i < steps; i++) Rpc.Update();
            }

            public HashSet<uint> PerceiveAll(List<JObject> events)
            {
                var ids = new HashSet<uint>();
                foreach (var ev in events.OrderBy(e => e.Value<long?>("tick") ?? long.MinValue))
                {
                    var name = ev.Value<string>("event") ?? throw new ArgumentException("An event needs 'event'.");
                    if (!eventActions.TryGetValue(name, out var action))
                        throw new ArgumentException($"Unknown event '{name}'. Known: {string.Join(", ", eventActions.Keys)}.");
                    AdvanceTo(ev.Value<long?>("tick"));

                    var x = Alias(ev.Value<string>("actor") ?? "other");
                    switch (ev["actor_relation"])
                    {
                        case JObject rel: SetRelation(x, rel); break;
                    }
                    Rpc.UpdateBelief($"Magnitude(SELF, {x})", Fmt(Clamp01(ev.Value<float?>("intensity") ?? 0.5f)));
                    Rpc.UpdateBelief("Focus(SELF)", x);
                    Rpc.UpdateBelief($"Stimulus(SELF, {x})", action);

                    Rpc.Perceive(Name.BuildName($"Event(Action-End, {x}, {action}, {P.Character})"));
                    var id = Rpc.EventRecords.Max(r => r.Id);
                    ids.Add(id);
                    memoryTick[id] = Now;
                    memoryEvent[id] = name;

                    // The character's decision rules (layer Belief) say how its relationship beliefs move.
                    var updates = Rpc.Decide(Name.BuildName("Belief"))
                        .Where(a => a.Target.ToString() == x && a.Name.GetNTerm(0).ToString() == "Believe")
                        .Select(a => (Rel: a.Name.GetNTerm(1).ToString(), Value: a.Name.GetNTerm(2).ToString()))
                        .ToList();
                    foreach (var u in updates)
                        if (float.TryParse(u.Value, NumberStyles.Float, CultureInfo.InvariantCulture, out var v))
                            Rpc.UpdateBelief($"{u.Rel}(SELF, {x})", Fmt(Clamp01(v)));

                    Rpc.UpdateBelief($"Stimulus(SELF, {x})", "None");
                    Rpc.UpdateBelief("Focus(SELF)", "None");
                }
                return ids;
            }

            public JObject Export()
            {
                var native = JObject.Parse(Iat.ToJson());
                var ch = (JObject)native["root"]["Characters"][0];
                var now = Now;

                var pool = (ch["EmotionalState"]["EmotionalPool"] as JArray)?.OfType<JObject>().ToList() ?? new List<JObject>();
                var records = (ch["AutobiographicMemory"]["records"] as JArray)?.OfType<JObject>().ToList() ?? new List<JObject>();

                var causes = new HashSet<uint>(pool.Select(e => e.Value<uint>("CauseId")));
                var keep = records
                    .Where(r => r.Value<string>("Type") == "Action-End" && actionEvents.ContainsKey(r.Value<string>("Action") ?? ""))
                    .OrderByDescending(r => r.Value<uint>("Id"))
                    .Select((r, i) => (r, i))
                    .Where(p => p.i < MemoryLimit || causes.Contains(p.r.Value<uint>("Id")))
                    .Select(p => p.r)
                    .OrderBy(r => r.Value<uint>("Id"))
                    .ToList();

                var memories = new JArray(keep.Select(r =>
                {
                    var id = r.Value<uint>("Id");
                    return new JObject
                    {
                        ["id"] = id,
                        ["event"] = memoryEvent.TryGetValue(id, out var e) ? e : actionEvents[r.Value<string>("Action")],
                        ["actor"] = Actor(r.Value<string>("Subject")),
                        ["tick"] = memoryTick.TryGetValue(id, out var t) ? t : now,
                    };
                }));

                var emotions = new JArray(pool.Where(e => keep.Any(r => r.Value<uint>("Id") == e.Value<uint>("CauseId"))).Select(e =>
                {
                    var id = e.Value<uint>("CauseId");
                    var target = e.Value<string>("Target");
                    return new JObject
                    {
                        ["type"] = e.Value<string>("EmotionType"),
                        ["intensity"] = Round(e.Value<float>("Intensity")),
                        ["target"] = string.IsNullOrEmpty(target) || target == Name.NIL_STRING ? null : Actor(target),
                        ["memory"] = id,
                        ["age"] = now - (memoryTick.TryGetValue(id, out var t) ? t : now),
                        ["decay"] = e["Decay"],
                        ["threshold"] = e["Threshold"],
                        ["valence"] = e["Valence"],
                        ["variables"] = e["AppraisalVariables"],
                        ["influences_mood"] = e["InfluenceMood"],
                    };
                }));

                var relations = new JObject();
                foreach (var alias in actorOf.Keys)
                {
                    var r = new JObject();
                    foreach (var rel in relationNames)
                        r[rel] = Round(float.Parse(Rpc.GetBeliefValue($"{Cap(rel)}(SELF, {alias})"), CultureInfo.InvariantCulture));
                    relations[actorOf[alias]] = r;
                }

                var goals = new JObject();
                foreach (var g in Rpc.GetAllGoals())
                    goals[g.Name.Replace("(SELF)", "")] = Round(g.Likelihood);

                return new JObject
                {
                    ["tick"] = now,
                    ["mood"] = Round(Rpc.Mood),
                    ["goals"] = goals,
                    ["relations"] = relations,
                    ["emotions"] = emotions,
                    ["memories"] = memories,
                };
            }
        }

        // ------------------------------------------------------------------ persona and bundle

        static Persona ResolvePersona(JToken persona)
        {
            string key = null;
            if (persona is JValue v) key = v.ToString();
            else if (persona is JObject o) key = o.Value<string>("archetype");

            if (key != null)
            {
                var norm = Norm(key);
                var hit = personas.Values.FirstOrDefault(p => Norm(p.Key) == norm);
                return hit ?? throw new ArgumentException($"Unknown persona '{key}'. Known: {string.Join(", ", personas.Keys)}.");
            }

            // A trait vector without an archetype borrows the dispositions and goals of the nearest archetype;
            // the traits themselves still drive every rule through the Trait beliefs.
            var traits = ResolveTraits(persona, null) ?? throw new ArgumentException("'persona' is an archetype name or {archetype?, traits}.");
            return personas.Values.OrderBy(p => p.Traits.Zip(traits, (a, b) => (a - b) * (a - b)).Sum()).First();
        }

        static float[] ResolveTraits(JToken persona, Persona fallback)
        {
            var t = (persona as JObject)?["traits"];
            if (t is JArray arr && arr.Count == traitNames.Length)
                return arr.Select(x => Clamp01(x.Value<float>())).ToArray();
            if (t is JObject dict)
            {
                var basis = fallback?.Traits ?? Enumerable.Repeat(0.5f, traitNames.Length).ToArray();
                return traitNames.Select((n, i) => Clamp01(dict.Value<float?>(n) ?? basis[i])).ToArray();
            }
            return fallback?.Traits;
        }

        static void EnsureBundle()
        {
            if (bundle != null) return;

            var path = Environment.GetEnvironmentVariable("FATIMA_SOCIAL_BUNDLE");
            if (string.IsNullOrEmpty(path))
                path = new[] { "/app/social/ogame-social.json", Path.Combine(AppContext.BaseDirectory, "social", "ogame-social.json"), "ogame-social.json" }
                    .FirstOrDefault(File.Exists);
            if (path == null || !File.Exists(path))
                throw new FileNotFoundException("The social scenario bundle (ogame-social.json) is not installed.");

            var b = JObject.Parse(File.ReadAllText(path));
            traitNames = b["traits"].Select(x => x.ToString()).ToArray();
            relationNames = b["relations"].Select(x => x.ToString()).ToArray();
            eventActions = ((JObject)b["events"]).Properties().ToDictionary(p => p.Name, p => p.Value.ToString());
            actionEvents = eventActions.ToDictionary(p => p.Value, p => p.Key);
            exchangeNames = b["exchanges"].Select(x => x.ToString()).ToArray();
            recordClassName = typeof(AutobiographicMemory.AM).Assembly.GetType("AutobiographicMemory.AM+ActionEvent")?.AssemblyQualifiedName
                ?? "AutobiographicMemory.AM+ActionEvent, AutobiographicMemory, Version=1.0.0.0, Culture=neutral, PublicKeyToken=null";

            foreach (var p in ((JObject)b["personas"]).Properties())
            {
                var scenario = (JObject)p.Value["scenario"];
                var goals = scenario["root"]["Characters"][0]["Goals"].ToDictionary(
                    g => g.Value<string>("Name").Replace("(SELF)", ""), g => g.Value<float>("Significance"));
                personas[p.Name] = new Persona
                {
                    Key = p.Name,
                    Character = p.Value.Value<string>("character"),
                    Assets = AssetStorage.FromJson(p.Value["assets"].ToString(Formatting.None)),
                    Scenario = scenario,
                    Goals = goals,
                    Traits = b["archetypes"][p.Name].Select(x => x.Value<float>()).ToArray(),
                };
            }
            bundle = b;
        }

        static bool Missing(string value) => value == null || value == Name.NIL_STRING;
        static string Norm(string s) => s.Replace("_", "").Replace("-", "").ToLowerInvariant();
        static string Cap(string s) => char.ToUpperInvariant(s[0]) + s.Substring(1);
        static float Clamp01(float v) => v < 0 ? 0 : v > 1 ? 1 : v;
        static string Fmt(float v) => Math.Round(v, 4).ToString("0.####", CultureInfo.InvariantCulture);
        static float Round(float v) => (float)Math.Round(v, 4);
    }
}
