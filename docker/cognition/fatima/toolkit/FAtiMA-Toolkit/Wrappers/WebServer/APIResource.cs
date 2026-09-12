using CommeillFaut;
using CommeillFaut.DTOs;
using GAIPS.Rage;
using IntegratedAuthoringTool;
using Newtonsoft.Json;
using RolePlayCharacter;
using System;
using System.Collections.Generic;
using System.Linq;
using Utilities;
using WellFormedNames;
using WorldModel.DTOs;

namespace WebServer
{
    public enum APIResourceType
    {
        SCENARIOS, 
        INSTANCES,
        DECISIONS, 
        CHARACTERS, 
        EMOTIONS,
        BELIEFS, 
        MEMORIES, 
        PERCEPTIONS,
        WORLDMODEL,
        TICK, 
        ACTIONS,
        ADMINKEY,
        KEY,
        // Added by OGameX: character state cannot otherwise be read back or restored,
        // which makes the driver unusable as a replaceable cognition projection.
        STATE,
        // Added by OGameX: the CiF social-exchange surface was not reachable at all.
        SOCIALEXCHANGES,
        SOCIALEXCHANGE
    }

    public class APIResource
    {
        public APIResourceType Type { get; private set; }
        public int URLSegmentSize { get; set; }
        public string URLFormat { get; set; }
        public string[] ValidOperations { get; set; }
        public string ValidBodyFormat { get; private set; }
        public Func<APIRequest, ServerState, string> Execute { get; private set; }

        //scenarios
        public static APIResource SCENARIOS = new APIResource()
        {
            Type = APIResourceType.SCENARIOS,
            Execute = HandleScenariosRequest,
            URLFormat = "/scenarios",
            ValidOperations = new string[] { "GET", "POST", "DELETE", "RESET" },
            URLSegmentSize = 1,
            ValidBodyFormat = "{\"scenario\" : \"<scenario.json>\", \"assets\" : \"\"<scenario.json>\"}"
        };

        //scenarios/{scenarioName}/instances
        public static APIResource INSTANCES = new APIResource()
        {
            Type = APIResourceType.INSTANCES,
            Execute = HandleInstancesRequest,
            URLFormat = "/scenarios/{scenarioName}/instances",
            ValidOperations = new string[] { "GET", "POST", "DELETE"},
            URLSegmentSize = 3
        };

        //scenarios/{scenarioName}/instances/{instanceId}/characters/{charName}/decisions
        public static APIResource DECISIONS = new APIResource()
        {
            Type = APIResourceType.DECISIONS,
            Execute = HandleDecisionsRequest,
            URLFormat = "/scenarios/{scenarioName}/instances/{instanceId}/characters/{charName}/decisions",
            ValidOperations = new string[] { "GET"},
            URLSegmentSize = 7
        };

        //scenarios/{scenarioName}/instances/{instanceId}/characters
        public static APIResource CHARACTERS = new APIResource()
        {
            Type = APIResourceType.CHARACTERS,
            Execute = HandleCharactersRequest,
            URLFormat = "/scenarios/{scenarioName}/instances/{instanceId}/characters",
            URLSegmentSize = 5,
            ValidOperations = new string[] { "GET" },
        };

        //scenarios/{scenarioName}/instances/{instanceId}/characters{characterName}
        public static APIResource EMOTIONS = new APIResource()
        {
            Type = APIResourceType.EMOTIONS,
            Execute = HandleEmotionRequest,
            URLFormat = "/scenarios/{scenarioName}/instances/{instanceId}/characters/{characterName}/emotions",
            URLSegmentSize = 7,
            ValidOperations = new string[] { "GET" },
        };

        //scenarios/{scenarioName}/instances/{instanceId}/characters/{charName}/beliefs
        public static APIResource BELIEFS = new APIResource()
        {
            Type = APIResourceType.BELIEFS,
            Execute = HandleBeliefsRequest,
            URLFormat = "/scenarios/{scenarioName}/instances/{instanceId}/characters/{charName}/beliefs",
            URLSegmentSize = 7,
            ValidOperations = new string[] { "GET", "POST" },
        };

        //scenarios/{scenarioName}/instances/{instanceId}/characters/{charName}/memories
        public static APIResource MEMORIES = new APIResource()
        {
            Type = APIResourceType.MEMORIES,
            Execute = HandleMemoriesRequest,
            URLFormat = "/scenarios/{scenarioName}/instances/{instanceId}/characters/{charName}/memories",
            URLSegmentSize = 7,
            ValidOperations = new string[] { "GET" },
        };

        //scenarios/{scenarioName}/instances/{instanceId}/characters/{charName}/perceptions
        public static APIResource PERCEPTIONS = new APIResource()
        {
            Type = APIResourceType.PERCEPTIONS,
            Execute = HandlePerceptionsRequest,
            URLFormat = "/scenarios/{scenarioName}/instances/{instanceId}/characters/{charName}/perceptions",
            URLSegmentSize = 7,
            ValidOperations = new string[] { "POST" },
        };

        //scenarios/{scenarioName}/instances/{instanceId}/worldmodel
        public static APIResource WORLDMODEL = new APIResource()
        {
            Type = APIResourceType.WORLDMODEL,
            Execute = HandleWorldModelRequest,
            URLFormat = "/scenarios/{scenarioName}/instances/{instanceId}/worldmodel",
            URLSegmentSize = 5,
            ValidOperations = new string[] { "POST" },
        };

        //scenarios/{scenarioName}/instances/{instanceId}/tick
        public static APIResource TICK = new APIResource()
        {
            Type = APIResourceType.TICK,
            Execute = HandleTickRequest,
            URLSegmentSize = 5,
            URLFormat = "/scenarios/{scenarioName}/instances/{instanceId}/tick",
            ValidOperations = new string[] { "GET" , "POST" },
        };

        //scenarios/{scenarioName}/instances/{instanceId}/actions
        public static APIResource ACTIONS = new APIResource()
        {
            Type = APIResourceType.ACTIONS,
            Execute = HandleActionsRequest,
            URLFormat = "/scenarios/{scenarioName}/instances/{instanceId}/actions",
            URLSegmentSize = 5,
            ValidOperations = new string[] { "POST" },
        };

        //adminkey
        public static APIResource ADMINKEY = new APIResource()
        {
            Type = APIResourceType.ADMINKEY,
            Execute = HandleAdminKeyRequest,
            URLFormat = "/adminkey",
            ValidOperations = new string[] { "POST" },
            URLSegmentSize = 1
        };

        //adminkey
        public static APIResource KEY = new APIResource()
        {
            Type = APIResourceType.KEY,
            Execute = HandleKeyRequest,
            URLFormat = "/scenarios/{scenarioName}/key",
            ValidOperations = new string[] { "GET" },
            URLSegmentSize = 3
        };

        //scenarios/{scenarioName}/instances/{instanceId}/state  (added by OGameX)
        //GET exports the whole instance as the scenario JSON format, POST replaces
        //the instance from that JSON using the scenario template's assets. Without it
        //nothing outside the process can persist or restore character state, so a
        //restart silently discards mood, emotions and beliefs.
        public static APIResource STATE = new APIResource()
        {
            Type = APIResourceType.STATE,
            Execute = HandleStateRequest,
            URLFormat = "/scenarios/{scenarioName}/instances/{instanceId}/state",
            URLSegmentSize = 5,
            ValidOperations = new string[] { "GET", "POST" },
        };

        //scenarios/{scenarioName}/instances/{instanceId}/characters/{charName}/socialexchanges  (added by OGameX)
        //GET lists the authored exchanges. POST with {"target": "X"} evaluates them for
        //that counterparty, returning the active step and the volition of each usable
        //mode. Before this, an exchange could only be created by authoring a scenario and
        //its stance was not readable at all.
        public static APIResource SOCIALEXCHANGES = new APIResource()
        {
            Type = APIResourceType.SOCIALEXCHANGES,
            Execute = HandleSocialExchangesRequest,
            URLFormat = "/scenarios/{scenarioName}/instances/{instanceId}/characters/{charName}/socialexchanges",
            URLSegmentSize = 7,
            ValidOperations = new string[] { "GET", "POST" },
        };

        //scenarios/{scenarioName}/instances/{instanceId}/characters/{charName}/socialexchange  (added by OGameX)
        //POST upserts one exchange at runtime instead of requiring a new scenario.
        public static APIResource SOCIALEXCHANGE = new APIResource()
        {
            Type = APIResourceType.SOCIALEXCHANGE,
            Execute = HandleSocialExchangeRequest,
            URLFormat = "/scenarios/{scenarioName}/instances/{instanceId}/characters/{charName}/socialexchange",
            URLSegmentSize = 7,
            ValidOperations = new string[] { "POST" },
        };

        public static APIResource[] Set = { ADMINKEY, SCENARIOS , KEY, INSTANCES, TICK, ACTIONS, CHARACTERS, EMOTIONS, PERCEPTIONS, DECISIONS, WORLDMODEL, BELIEFS, MEMORIES, STATE, SOCIALEXCHANGES, SOCIALEXCHANGE };

        public static APIResource FromString(string type)
        {
            return Set.FirstOrDefault(r => r.Type.ToString().ToLower() == type);
        }

        public bool HasAuthorization(string key, string scenarioName, ServerState serverState)
        {
            if (serverState.AdminKey != null && key == serverState.AdminKey) return true;

            if (scenarioName != null && serverState.ScenarioKeys.ContainsKey(scenarioName))
            {
                var scenarioKey = serverState.ScenarioKeys[scenarioName];

                return key == scenarioKey;
            }
            else
            {
                return true;
            }
        }

        public bool IsHttpMethodValid(APIRequest req)
        {
            return this.ValidOperations.Any(o => o.EqualsIgnoreCase(req.Method.ToString()));
        }

        private static string HandleKeyRequest(APIRequest req, ServerState serverState)
        {
            return JsonConvert.SerializeObject(serverState.ScenarioKeys[req.ScenarioName]);
        }

        private static string HandleAdminKeyRequest(APIRequest req, ServerState serverState)
        {
            var newKey = req.RequestBody;

            if (string.IsNullOrWhiteSpace(newKey))
 
                return JsonConvert.SerializeObject("Error: Request Body is empty!");
            if (newKey.Equals(Name.NIL_STRING))
                return JsonConvert.SerializeObject("Error: Key cannot be null (-)!");

            if (!string.IsNullOrEmpty(serverState.AdminKey)) //key already defined
            {
                if(serverState.AdminKey == req.Key)
                {
                    serverState.AdminKey = newKey;
                    return JsonConvert.SerializeObject("Admin key has been updated!");
                }
                else
                {
                    return JsonConvert.SerializeObject(APIErrors.ERROR_ACCESS_DENIED);
                }
            }
            else 
            {
                serverState.AdminKey = newKey;
                return JsonConvert.SerializeObject("Admin key has been defined!");
            }
            
        }

        private static string HandleScenariosRequest(APIRequest req, ServerState serverState)
        {
            if (req.Method == HTTPMethod.GET) return JsonConvert.SerializeObject(serverState.Scenarios.Keys);
            
            if (req.Method == HTTPMethod.POST)
            {
                if (!string.IsNullOrEmpty(req.RequestBody))
                {
                    IntegratedAuthoringToolAsset iat;
                    try
                    {
                        var request = JsonConvert.DeserializeObject<CreateScenarioRequestDTO>(req.RequestBody);
                        var assetStorage = AssetStorage.FromJson(request.Assets);
                        iat = IntegratedAuthoringToolAsset.FromJson(request.Scenario, assetStorage);

                        serverState.Scenarios[iat.ScenarioName.ToLower()] = new IntegratedAuthoringToolAsset[HTTPFAtiMAServer.MAX_INSTANCES + 1]; 

                        //original is kept at 0 and will be left unchanged
                        serverState.Scenarios[iat.ScenarioName.ToLower()][0] = IntegratedAuthoringToolAsset.FromJson(request.Scenario, assetStorage); 
                        
                        //instance of id 1 is automatically created
                        serverState.Scenarios[iat.ScenarioName.ToLower()][1] = IntegratedAuthoringToolAsset.FromJson(request.Scenario, assetStorage);
                        if (!string.IsNullOrEmpty(req.Key))
                        {
                            serverState.ScenarioKeys[iat.ScenarioName.ToLower()] = req.Key;
                        }
                    }
                    catch (Exception ex)
                    {
                        return JsonConvert.SerializeObject(ex.Message);
                    }
                    return JsonConvert.SerializeObject("Scenario named '" + iat.ScenarioName + "' created containing " +
                        "'" + iat.Characters.Count() + "' characters");
                }
                else
                {
                    return JsonConvert.SerializeObject("Error: Empty body in a create scenario request!");
                }
            }
            if (req.Method == HTTPMethod.DELETE)
            {
                serverState.Scenarios.TryRemove(req.ScenarioName, out var aux1);
                serverState.ScenarioKeys.TryRemove(req.ScenarioName, out var aux2);
                return JsonConvert.SerializeObject("Scenario deleted.");
            }

          

            return APIErrors.ERROR_INVALID_HTTP_METHOD;
        }


        private static string HandleInstancesRequest(APIRequest req, ServerState serverState)
        {
            if (req.Method == HTTPMethod.GET)
            {
                var result = new List<int>();
                for (int i = 0; i < HTTPFAtiMAServer.MAX_INSTANCES + 1; i++)
                {
                    if (serverState.Scenarios[req.ScenarioName][i] != null) result.Add(i);
                }
                return JsonConvert.SerializeObject(result);
            }
            else if (req.Method == HTTPMethod.POST)
            {
                for (int i = 0; i < HTTPFAtiMAServer.MAX_INSTANCES + 1; i++)
                {
                    if (serverState.Scenarios[req.ScenarioName][i] == null)
                    {
                        var scenarioJson = serverState.Scenarios[req.ScenarioName][0].ToJson();
                        serverState.Scenarios[req.ScenarioName][i] = IntegratedAuthoringToolAsset.FromJson(scenarioJson, serverState.Scenarios[req.ScenarioName][0].Assets);
                        return JsonConvert.SerializeObject(i);
                    }
                }
            }
            else if (req.Method == HTTPMethod.DELETE)
            {
                serverState.Scenarios[req.ScenarioName][req.ScenarioInstance] = null;
                return JsonConvert.SerializeObject("Instance deleted.");
            }
             
            return APIErrors.ERROR_INVALID_HTTP_METHOD;
        }

        private static string HandleDecisionsRequest(APIRequest req, ServerState serverState)
        {
            if (req.Method != HTTPMethod.GET)
                return JsonConvert.SerializeObject("Error: Invalid operation");

            List<DecisionDTO> resultDTO = new List<DecisionDTO>();

            var scenario = serverState.Scenarios[req.ScenarioName][req.ScenarioInstance];
            var rpc = scenario.Characters.Where(r => r.CharacterName.ToString().EqualsIgnoreCase(req.CharacterName)).FirstOrDefault();
            var decisions = rpc?.Decide();
            if (decisions.Any())
            {
                foreach (var d in decisions)
                {
                    string utterance = null;
                    string guid = null;
                    if (string.Equals(d.Key.ToString(), IATConsts.DIALOG_ACTION_KEY, StringComparison.OrdinalIgnoreCase))
                    {
                        try
                        {
                            var dialogAction = scenario.GetDialogAction(d, out utterance);
                            utterance = dialogAction.Utterance;
                            guid = dialogAction.UtteranceId;
                        }
                        catch (Exception)
                        {
                            return JsonConvert.SerializeObject(string.Format(APIErrors.ERROR_UNKOWN_SPEAK_ACTION, d));
                        }
                        utterance = rpc.ProcessWithBeliefs(utterance);
                    }
                    resultDTO.Add(new DecisionDTO { Action = d.Name.ToString(), Target = d.Target.ToString(), Utterance = utterance, Utility = d.Utility, ID = guid });
                }
            }
            return JsonConvert.SerializeObject(resultDTO);
        }


        private static string HandleCharactersRequest(APIRequest req, ServerState serverState)
        {
            if (req.Method != HTTPMethod.GET)
                return JsonConvert.SerializeObject("Error: Invalid operation");


            var result = new List<CharacterDTO>();
            var scenario = serverState.Scenarios[req.ScenarioName][req.ScenarioInstance];
            foreach (var rpc in scenario.Characters)
            {
                result.Add(new CharacterDTO { Name = rpc.CharacterName.ToString(), Emotions = rpc.GetAllActiveEmotions(), Mood = rpc.Mood });
            }
            return JsonConvert.SerializeObject(result);
        }

        private static string HandleEmotionRequest(APIRequest req, ServerState serverState)
        {
            if (req.Method != HTTPMethod.GET)
                return JsonConvert.SerializeObject("Error: Invalid operation");
          
            var rpc = serverState.Scenarios[req.ScenarioName][req.ScenarioInstance].Characters.First(x=> x.CharacterName.ToString().ToLower() == req.CharacterName.ToLower());
               var result = new CharacterDTO { Name = rpc.CharacterName.ToString(), Emotions = rpc.GetAllActiveEmotions(), Mood = rpc.Mood };
            
            return JsonConvert.SerializeObject(result);
        }


        private static string HandleActionsRequest(APIRequest req, ServerState serverState)
        {
            if (req.Method != HTTPMethod.POST)
                return JsonConvert.SerializeObject("Error: Invalid operation");

            IEnumerable<EffectDTO> eventEffects = null;

            var scenario = serverState.Scenarios[req.ScenarioName][req.ScenarioInstance];

            if (!string.IsNullOrEmpty(req.RequestBody))
            {
                Name ev = null;
                try
                {
                    var requests = JsonConvert.DeserializeObject<ExecuteRequestDTO[]>(req.RequestBody);
                    foreach (var a in requests)
                    {
                        ev = EventHelper.ActionEnd(a.Subject, a.Action, a.Target);
                        foreach (var rpc in scenario.Characters)
                        {
                            rpc.Perceive(ev);
                        }
                        if (scenario.WorldModel != null)
                        {
                            eventEffects = scenario.WorldModel.Simulate(new[] { ev });
                            foreach (var eff in eventEffects)
                            {
                                if (eff.ObserverAgent == WellFormedNames.Name.UNIVERSAL_SYMBOL)
                                {
                                    foreach (var rpc in scenario.Characters)
                                    {
                                        rpc.Perceive(EventHelper.PropertyChange(eff.PropertyName, eff.NewValue, (Name)a.Subject));
                                    }
                                }
                                else
                                {
                                    var obs = scenario.Characters.Where(r => r.CharacterName == eff.ObserverAgent).FirstOrDefault();
                                    {
                                        obs?.Perceive(EventHelper.PropertyChange(eff.PropertyName, eff.NewValue, (Name)a.Subject));
                                    }
                                }
                            }
                        }
                    }
                    return JsonConvert.SerializeObject((string.Format("{0} actions(s) executed and {1} effects triggered", requests.Count(), eventEffects?.Count())));
                }
                catch (Exception ex)
                {
                    return JsonConvert.SerializeObject(String.Format(APIErrors.ERROR_EXCEPTION_PERCEIVE, ev, ex.Message));
                }
            }
            else
            {
                return JsonConvert.SerializeObject(APIErrors.ERROR_EMPTY_ACTION_REQUEST_LIST);
            }
        }



        private static string HandleMemoriesRequest(APIRequest req, ServerState serverState)
        {
            if (req.Method != HTTPMethod.GET)
                return JsonConvert.SerializeObject("Error: Invalid operation");

            var scenario = serverState.Scenarios[req.ScenarioName][req.ScenarioInstance];
            try
            {
                var rpc = scenario.Characters.Where(r => r.CharacterName.ToString().EqualsIgnoreCase(req.CharacterName)).FirstOrDefault();
                var result = rpc.EventRecords;
                return JsonConvert.SerializeObject(result);
            }
            catch (Exception ex)
            {
                return JsonConvert.SerializeObject(string.Format(APIErrors.ERROR_EXCEPTION,ex.Message));
            }
        }

        private static string HandleBeliefsRequest(APIRequest req, ServerState serverState)
        {
            if (req.Method == HTTPMethod.POST)
                return UpdateBeliefRequest(req, serverState);

            if (req.Method != HTTPMethod.GET)
                return JsonConvert.SerializeObject("Error: Invalid operation");

            try
            {
                var scenario = serverState.Scenarios[req.ScenarioName][req.ScenarioInstance];
                var rpc = scenario.Characters.Where(r => r.CharacterName.ToString().EqualsIgnoreCase(req.CharacterName)).FirstOrDefault();
                var beliefResult = rpc.GetAllBeliefs();
                return JsonConvert.SerializeObject(beliefResult);
            }
            catch (Exception ex)
            {
                return JsonConvert.SerializeObject(string.Format(APIErrors.ERROR_EXCEPTION, ex.Message));
            }
        }


        // Added by OGameX. Exposes the CiF surface: read the authored exchanges,
        // evaluate them for a counterparty, and upsert one at runtime.
        private static string HandleSocialExchangesRequest(APIRequest req, ServerState serverState)
        {
            var exchanges = SocialExchangeAssetFor(req, serverState, out string error);

            if (exchanges == null)
                return JsonConvert.SerializeObject(error);

            if (req.Method == HTTPMethod.GET)
                // Name and Mode are WellFormedName values, which Newtonsoft would
                // serialize as their property bag rather than their text, so they are
                // projected to strings for a stable wire format.
                return JsonConvert.SerializeObject(exchanges.GetAllSocialExchanges().Select(exchange => new
                {
                    exchange.Id,
                    Name = exchange.Name?.ToString(),
                    exchange.Description,
                    exchange.Steps,
                    Target = exchange.Target?.ToString(),
                    exchange.StartingConditions,
                    InfluenceRules = exchange.InfluenceRules?.Select(rule => new
                    {
                        rule.Id,
                        Mode = rule.Mode?.ToString(),
                        rule.Value,
                        rule.Rule,
                    }),
                }));

            if (req.Method != HTTPMethod.POST)
                return JsonConvert.SerializeObject(APIErrors.ERROR_INVALID_HTTP_METHOD);

            var body = RequestBodyMap(req);
            var target = body != null && body.ContainsKey("target") ? body["target"] : null;

            if (string.IsNullOrEmpty(target))
                return JsonConvert.SerializeObject("Error: An evaluation body requires 'target'.");

            return JsonConvert.SerializeObject(exchanges.EvaluateExchanges(Name.BuildName(target)));
        }

        private static string HandleSocialExchangeRequest(APIRequest req, ServerState serverState)
        {
            if (req.Method != HTTPMethod.POST)
                return JsonConvert.SerializeObject(APIErrors.ERROR_INVALID_HTTP_METHOD);

            var exchanges = SocialExchangeAssetFor(req, serverState, out string error);

            if (exchanges == null)
                return JsonConvert.SerializeObject(error);

            if (string.IsNullOrEmpty(req.RequestBody))
                return JsonConvert.SerializeObject("Error: Request Body is empty!");

            SocialExchangeDTO dto;
            try
            {
                dto = JsonConvert.DeserializeObject<SocialExchangeDTO>(req.RequestBody);
            }
            catch (Exception ex)
            {
                return JsonConvert.SerializeObject(string.Format(APIErrors.ERROR_EXCEPTION, ex.Message));
            }

            if (dto == null || dto.Name == null)
                return JsonConvert.SerializeObject("Error: An exchange body requires 'Name'.");

            return JsonConvert.SerializeObject(exchanges.AddOrUpdateExchange(dto).ToString());
        }

        private static Dictionary<string, string> RequestBodyMap(APIRequest req)
        {
            if (string.IsNullOrEmpty(req.RequestBody))
                return null;

            try
            {
                return JsonConvert.DeserializeObject<Dictionary<string, string>>(req.RequestBody);
            }
            catch (Exception)
            {
                return null;
            }
        }

        private static CommeillFautAsset SocialExchangeAssetFor(APIRequest req, ServerState serverState, out string error)
        {
            error = null;

            if (!serverState.Scenarios.TryGetValue(req.ScenarioName, out IntegratedAuthoringToolAsset[] instances))
            {
                error = APIErrors.ERROR_UNKNOWN_SCENARIO;
                return null;
            }

            var scenario = instances[req.ScenarioInstance];

            if (scenario == null)
            {
                error = "Error: Instance does not exist.";
                return null;
            }

            var rpc = scenario.Characters.Where(r => r.CharacterName.ToString().EqualsIgnoreCase(req.CharacterName)).FirstOrDefault();

            if (rpc == null)
            {
                error = "Error: Unknown character.";
                return null;
            }

            if (rpc.m_commeillFautAsset == null)
            {
                error = "Error: Character has no social exchange asset.";
                return null;
            }

            return rpc.m_commeillFautAsset;
        }

        // Added by OGameX. The stock server can only observe beliefs, so a caller can
        // never supply context such as relationship trust for an appraisal.
        private static string UpdateBeliefRequest(APIRequest req, ServerState serverState)
        {
            if (string.IsNullOrEmpty(req.RequestBody))
                return JsonConvert.SerializeObject("Error: Request Body is empty!");

            Dictionary<string, string> belief;
            try
            {
                belief = JsonConvert.DeserializeObject<Dictionary<string, string>>(req.RequestBody);
            }
            catch (Exception ex)
            {
                return JsonConvert.SerializeObject(string.Format(APIErrors.ERROR_EXCEPTION, ex.Message));
            }

            if (belief == null || !belief.ContainsKey("name") || !belief.ContainsKey("value"))
                return JsonConvert.SerializeObject("Error: A belief body requires 'name' and 'value'.");

            var scenario = serverState.Scenarios[req.ScenarioName][req.ScenarioInstance];
            var rpc = scenario.Characters.Where(r => r.CharacterName.ToString().EqualsIgnoreCase(req.CharacterName)).FirstOrDefault();

            if (rpc == null)
                return JsonConvert.SerializeObject("Error: Unknown character.");

            rpc.UpdateBelief(belief["name"], belief["value"]);

            return JsonConvert.SerializeObject("Belief updated.");
        }

        // Added by OGameX: exports and restores a whole instance so character state
        // survives a restart and a driver swap cannot silently discard it.
        private static string HandleStateRequest(APIRequest req, ServerState serverState)
        {
            if (!serverState.Scenarios.TryGetValue(req.ScenarioName, out IntegratedAuthoringToolAsset[] instances))
                return JsonConvert.SerializeObject(APIErrors.ERROR_UNKNOWN_SCENARIO);

            if (req.ScenarioInstance < 0 || req.ScenarioInstance >= instances.Length)
                return JsonConvert.SerializeObject("Error: Instance index is out of range.");

            if (req.Method == HTTPMethod.GET)
                return ExportInstanceState(instances, req.ScenarioInstance);

            if (req.Method == HTTPMethod.POST)
                return ImportInstanceState(instances, req.ScenarioInstance, req.RequestBody);

            return JsonConvert.SerializeObject(APIErrors.ERROR_INVALID_HTTP_METHOD);
        }

        private static string ExportInstanceState(IntegratedAuthoringToolAsset[] instances, int instance)
        {
            var scenario = instances[instance];

            if (scenario == null)
                return JsonConvert.SerializeObject("Error: Instance does not exist.");

            return scenario.ToJson();
        }

        private static string ImportInstanceState(IntegratedAuthoringToolAsset[] instances, int instance, string body)
        {
            if (instances[0] == null)
                return JsonConvert.SerializeObject("Error: Scenario template is missing.");

            if (string.IsNullOrEmpty(body))
                return JsonConvert.SerializeObject("Error: Request Body is empty!");

            try
            {
                instances[instance] = IntegratedAuthoringToolAsset.FromJson(body, instances[0].Assets);
            }
            catch (Exception ex)
            {
                return JsonConvert.SerializeObject(string.Format(APIErrors.ERROR_EXCEPTION, ex.Message));
            }

            return JsonConvert.SerializeObject("Instance state restored.");
        }

        private static string HandlePerceptionsRequest(APIRequest req, ServerState serverState)
        {
            string[] events = Array.Empty<string>();

            if (!string.IsNullOrEmpty(req.RequestBody))
            {
                var scenario = serverState.Scenarios[req.ScenarioName][req.ScenarioInstance];
                events = JsonConvert.DeserializeObject<string[]>(req.RequestBody);
                foreach (var ev in events)
                {
                    Name evName = null;
                    try
                    {
                        evName = WellFormedNames.Name.BuildName(ev);
                        var rpc = scenario.Characters.Where(r => r.CharacterName.ToString().EqualsIgnoreCase(req.CharacterName)).FirstOrDefault();
                        rpc.Perceive(evName);
                    }
                    catch (Exception ex)
                    {
                        return JsonConvert.SerializeObject(string.Format(APIErrors.ERROR_EXCEPTION_PERCEIVE, ev, ex.Message));
                    }
                }
            }
            else
            {
                return JsonConvert.SerializeObject(APIErrors.ERROR_EMPTY_EVENT_LIST);
            }
            return JsonConvert.SerializeObject(string.Format("{0} event(s) perceived by {1}", events.Count(), req.CharacterName));
        }

        private static string HandleWorldModelRequest(APIRequest req, ServerState serverState)
        {
            string[] events = Array.Empty<string>();


            if (!string.IsNullOrEmpty(req.RequestBody))
            {
                var scenario = serverState.Scenarios[req.ScenarioName][req.ScenarioInstance];
                events = JsonConvert.DeserializeObject<string[]>(req.RequestBody);
                List<Name> eventList = new List<Name>();
                foreach (var ev in events)
                {
                    Name evName = null;
                    try
                    {
                        evName = WellFormedNames.Name.BuildName(ev);

                        eventList.Add(evName);



                    }
                    catch (Exception ex)
                    {
                        return JsonConvert.SerializeObject(string.Format(APIErrors.ERROR_EXCEPTION_PERCEIVE, ev, ex.Message));
                    }
                }

                    var effects = scenario.WorldModel.Simulate(eventList.ToArray());

                foreach(var rpc in scenario.Characters)
                {
                    foreach (var e in eventList)
                        rpc.Perceive(e);
                }

                    // For each effect 
                    foreach (var eff in effects)
                    {

                        // For each Role Play Character
                        foreach (var rpc in scenario.Characters)
                        {

                            //If the "Observer" part of the effect corresponds to the name of the agent or if it is a universal symbol
                            if (eff.ObserverAgent != rpc.CharacterName && eff.ObserverAgent != (Name)"*") continue;
                            //Apply that consequence to the agent
                            rpc.Perceive(EventHelper.PropertyChange(eff.PropertyName, eff.NewValue, rpc.CharacterName));

                        }
                    }
                
            }
            else
            {
                return JsonConvert.SerializeObject(APIErrors.ERROR_EMPTY_EVENT_LIST);
            }
            return JsonConvert.SerializeObject(string.Format("{0} event(s) perceived by {1}", events.Count(), req.CharacterName));
        }

        private static string HandleTickRequest(APIRequest req, ServerState serverState)
        {
            var scenario = serverState.Scenarios[req.ScenarioName][req.ScenarioInstance];

            if (req.Method == HTTPMethod.GET)
            {
                return JsonConvert.SerializeObject(scenario.Characters.First().Tick);
            }

            if (req.Method == HTTPMethod.POST)
            {
                int ticks = 0;
                if (string.IsNullOrEmpty(req.RequestBody))
                {
                    foreach (var rpc in scenario.Characters)
                    {
                        rpc.Update();
                    }
                }
                else
                {
                    try
                    {
                        ticks = JsonConvert.DeserializeObject<int>(req.RequestBody);
                        foreach (var rpc in scenario.Characters)
                        {
                            for (int i = 0; i < ticks; i++)
                            {
                                rpc.Update();
                            }
                        }
                    }
                    catch (Exception)
                    {
                        return JsonConvert.SerializeObject(APIErrors.ERROR_EXCEPTION_UPDATE);
                    }
                }
                return JsonConvert.SerializeObject(string.Format("Updated {0} ticks!", ticks));
            }
            return JsonConvert.SerializeObject("Error: Invalid Request");
        }
    }
}
