using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using ActionLibrary.DTOs;
using CommeillFaut;
using CommeillFaut.DTOs;
using Conditions.DTOs;
using EmotionalAppraisal.DTOs;
using GAIPS.Rage;
using IntegratedAuthoringTool;
using IntegratedAuthoringTool.DTOs;
using WellFormedNames;
using WorldModel.DTOs;

namespace CognitionSeeder
{
    // Authors the module's cognition scenario through the real FAtiMA APIs.
    //
    // The asset format must be produced by the library. The CiF DTO key
    // (`_SocialExchangesDtos`) differs from the serialized key (`SocialExchanges`), so a
    // hand-written exchange loads as an empty set without any error. Generating the
    // scenario here keeps the committed fixture reproducible.
    //
    // The scenario is deliberately one scenario with one character so that a single
    // integrated character state serves both contracts, as the module requires.
    //
    // Usage: CognitionSeeder <outputDir> <rapport>
    class Program
    {
        // The toolkit spells this variable "Probablity". The spelling is part of the
        // serialized contract, so it is reproduced verbatim rather than corrected.
        const string GoalSuccessProbability = "Goal Success Probablity";

        const string Counterparty = "Other";

        // One character per module archetype, so the same stimulus reaches authored
        // persona data instead of one shared policy. Goal significance is the only
        // per-persona weight, which keeps the differentiation auditable: Fear is
        // |goal delta| x significance, so a Fleeter fears a threat less than a Turtle.
        static readonly (string Name, float SafetySignificance)[] Archetypes =
        {
            ("Miner", 1.0f),
            ("Turtle", 1.0f),
            ("Fleeter", 0.3f),
            ("Trader", 0.7f),
            ("Casual", 0.5f),
        };

        static void Main(string[] args)
        {
            string outDir = args.Length > 0 ? args[0] : ".";
            int rapport = args.Length > 1 ? int.Parse(args[1]) : 5;

            Directory.CreateDirectory(outDir);

            var iat = new IntegratedAuthoringToolAsset
            {
                ScenarioName = "OgameCognition",
                ScenarioDescription = "OGameX cognition driver: OCC appraisal and a CiF social exchange"
            };

            var assets = new AssetStorage();
            iat.Assets = assets;

            // The exchange is authored before the character loads its assets, so the
            // character binds to this exact exchange through the real loader.
            var cif = CommeillFautAsset.CreateInstance(assets);
            cif.AddOrUpdateExchange(new SocialExchangeDTO
            {
                Name = Name.BuildName("CooperativeMove"),
                Description = "Whether this counterparty's standing supports a cooperative move",
                Steps = "Start, Give, End",
                // The target must be a variable: VolitionValue builds a Substitution from
                // it, and Substitution rejects any non-variable name.
                Target = Name.BuildName("[x]"),
                StartingConditions = new ConditionSetDTO { ConditionSet = new[] { "RapportLevel(SELF, [x]) > 3" } },
                InfluenceRules = new List<InfluenceRuleDTO>
                {
                    new InfluenceRuleDTO
                    {
                        Rule = new ConditionSetDTO { ConditionSet = new[] { "RapportLevel(SELF, [x]) > 3" } },
                        Value = 7,
                        Mode = Name.BuildName("*"),
                    }
                }
            });
            cif.Save();

            foreach (var archetype in Archetypes)
                Author(iat, assets, archetype.Name, archetype.SafetySignificance, rapport);

            AddDialogue(iat, "s1", "s2", "Question", "Neutral", "What are you doing?");
            AddDialogue(iat, "s1", "s3", "Question", "Neutral", "How are you feeling?");
            AddDialogue(iat, "s2", "s4", "Answer", "Neutral", "Nothing special.");
            AddDialogue(iat, "s3", "s4", "Answer", "Neutral", "I am feeling great.");

            var speak = Name.BuildName("Event(Action-End, [s], Speak([cs], [ns], [m], [st]), [t])");
            iat.WorldModel.addActionTemplate(speak, 1);
            iat.WorldModel.AddActionEffect(speak, new EffectDTO
            {
                PropertyName = Name.BuildName("DialogueState([s])"),
                NewValue = Name.BuildName("[ns]"),
                ObserverAgent = Name.BuildName("[t]"),
            });
            iat.WorldModel.AddActionEffect(speak, new EffectDTO
            {
                PropertyName = Name.BuildName("DialogueState([t])"),
                NewValue = Name.BuildName("[ns]"),
                ObserverAgent = Name.BuildName("[s]"),
            });

            File.WriteAllText(Path.Combine(outDir, "scenario.json"), iat.ToJson());
            File.WriteAllText(Path.Combine(outDir, "assets.json"), iat.Assets.ToJson() ?? "[]");

            Console.WriteLine($"Wrote OgameCognition (rapport={rapport}) to {Path.GetFullPath(outDir)}");
        }

        static void Author(IntegratedAuthoringToolAsset iat, AssetStorage assets, string archetype, float safetySignificance, int rapport)
        {
            iat.AddNewCharacter((Name)archetype);
            var character = iat.Characters.First(c => c.CharacterName == (Name)archetype);
            character.LoadAssociatedAssets(assets);

            character.Mood = 0;
            character.UpdateBelief("DialogueState(Player)", "s1");
            character.UpdateBelief($"RapportLevel(SELF,{Counterparty})", rapport.ToString());

            // Fear is a prospect emotion rather than a well-being one: OCC derives it from
            // a goal whose success probability the event lowers, so the character needs a
            // goal for the threat branch to be reachable at all.
            character.AddOrUpdateGoal(new GoalDTO
            {
                Name = "Safe(SELF)",
                Significance = safetySignificance,
                Likelihood = 1,
            });

            var appraisal = character.m_emotionalAppraisalAsset;

            // The adapter supplies signed OCC values as beliefs and only perceives the
            // event. Sign lives in the payload because a rule cannot negate a variable:
            // "-[d]" is rejected as an ill-formed name.
            //
            // Conditions bind the counterparty to the same variable the event template
            // uses. A property referenced with SELF alone has no second argument to unify
            // against, and the scenario then fails to load with a dynamic-property
            // collision.
            var signed = new[]
            {
                AppraisalVariable("Desirability", "[d]", "-"),
                AppraisalVariable("Praiseworthiness", "[d]", "[x]"),
            };
            AddRule(appraisal, $"Event(Action-End, [x], Aid, {Name.SELF_SYMBOL})",
                new[] { "StimulusDesirability(SELF, [x]) = [d]" }, signed);
            AddRule(appraisal, $"Event(Action-End, [x], Harm, {Name.SELF_SYMBOL})",
                new[] { "StimulusDesirability(SELF, [x]) = [d]" }, signed);
            AddRule(appraisal, $"Event(Action-End, [x], Threaten, {Name.SELF_SYMBOL})",
                new[] { "StimulusThreat(SELF, [x]) = [d]" },
                new[] { AppraisalVariable(GoalSuccessProbability, "[d]", "Safe(SELF)") });

            appraisal.Save();

            var decisions = character.m_emotionalDecisionMakingAsset;

            // The CiF-gated rule proves the exchange actually reaches decision making:
            // it is satisfiable only while the exchange's Volition clears 5.
            var gated = decisions.AddActionRule(new ActionRuleDTO
            {
                Action = Name.BuildName("Speak([cs],[ns],[m],Neutral)"),
                Target = Name.BuildName("[x]"),
                Priority = Name.BuildName("9"),
                Layer = Name.BuildName("-"),
            });
            decisions.AddRuleCondition(gated, "DialogueState([x]) = [cs]");
            // The exchange keeps a variable target, but the condition names the
            // counterparty concretely: an unbound [x] resolves to no possible target
            // inside the Volition dynamic property, so the rule would never fire.
            decisions.AddRuleCondition(gated, $"Volition(CooperativeMove, Start, {Counterparty}, *) >= 5");
            decisions.AddRuleCondition(gated, "ValidDialogue([cs],[ns],[m],Neutral) = True");

            var generic = decisions.AddActionRule(new ActionRuleDTO
            {
                Action = Name.BuildName("Speak([cs],[ns],[m],Neutral)"),
                Target = Name.BuildName("[x]"),
                Priority = Name.BuildName("1"),
                Layer = Name.BuildName("-"),
            });
            decisions.AddRuleCondition(generic, "DialogueState([x]) = [cs]");
            decisions.AddRuleCondition(generic, "ValidDialogue([cs],[ns],[m],Neutral) = True");

            decisions.Save();
        }

        static void AddRule(EmotionalAppraisal.EmotionalAppraisalAsset appraisal, string eventTemplate, string[] conditions, AppraisalVariableDTO[] variables)
        {
            appraisal.AddOrUpdateAppraisalRule(new AppraisalRuleDTO
            {
                EventMatchingTemplate = Name.BuildName(eventTemplate),
                AppraisalVariables = new EmotionalAppraisal.AppraisalVariables(new List<AppraisalVariableDTO>(variables)),
                Conditions = new ConditionSetDTO { ConditionSet = conditions },
            });
        }

        static AppraisalVariableDTO AppraisalVariable(string name, string value, string target)
        {
            return new AppraisalVariableDTO
            {
                Name = name,
                Value = Name.BuildName(value),
                Target = Name.BuildName(target),
            };
        }

        static void AddDialogue(IntegratedAuthoringToolAsset iat, string current, string next, string meaning, string style, string utterance)
        {
            iat.AddDialogAction(new DialogueStateActionDTO
            {
                CurrentState = current,
                NextState = next,
                Meaning = meaning,
                Style = style,
                Utterance = utterance,
            });
        }
    }
}
