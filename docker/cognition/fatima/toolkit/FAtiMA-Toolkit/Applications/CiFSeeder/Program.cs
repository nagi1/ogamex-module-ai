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

namespace CiFSeeder
{
    // Authors a CiF scenario through the real FAtiMA APIs. The serialized asset
    // format is produced by the library itself: the DTO key (`_SocialExchangesDtos`)
    // differs from the serialized key (`SocialExchanges`), so hand-written JSON
    // silently fails to load.
    //
    // Usage: CiFSeeder <outputDir> <rapport> <scenarioName>
    class Program
    {
        static void Main(string[] args)
        {
            string outDir = args.Length > 0 ? args[0] : ".";
            int rapport = args.Length > 1 ? int.Parse(args[1]) : 5;
            string scenarioName = args.Length > 2 ? args[2] : "CiFProbe";

            Directory.CreateDirectory(outDir);

            var iat = new IntegratedAuthoringToolAsset
            {
                ScenarioName = scenarioName,
                ScenarioDescription = "CiF probe: a social exchange whose Volition gates a decision rule"
            };

            var assets = new AssetStorage();
            iat.Assets = assets;

            // The exchange is authored before the character loads its assets, so the
            // character binds to this exact exchange through the real loader.
            var cif = CommeillFautAsset.CreateInstance(assets);
            cif.AddOrUpdateExchange(new SocialExchangeDTO
            {
                Name = Name.BuildName("GiveMetal"),
                Description = "Buyer asks for metal",
                Steps = "Start, Give, End",
                // The target must be a variable: VolitionValue builds a Substitution
                // from it, and Substitution rejects any non-variable name.
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

            iat.AddNewCharacter((Name)"John");
            var john = iat.Characters.First(c => c.CharacterName == (Name)"John");
            john.LoadAssociatedAssets(assets);

            john.Mood = 0;
            john.UpdateBelief("DialogueState(Player)", "s1");
            john.UpdateBelief("RapportLevel(SELF,Player)", rapport.ToString());

            var ea = john.m_emotionalAppraisalAsset;
            ea.AddOrUpdateAppraisalRule(new AppraisalRuleDTO
            {
                EventMatchingTemplate = Name.BuildName("Event(Action-End, [x], Smile, SELF)"),
                AppraisalVariables = new EmotionalAppraisal.AppraisalVariables(new List<AppraisalVariableDTO>
                {
                    new AppraisalVariableDTO
                    {
                        Name = "Desirability",
                        Value = Name.BuildName("[d]"),
                        Target = Name.BuildName("-"),
                    }
                }),
                Conditions = ConditionsFrom("RapportLevel(SELF,[x]) = [d]"),
            });
            ea.Save();

            var edm = john.m_emotionalDecisionMakingAsset;

            // CiF-driven rule: only satisfiable when the exchange's Volition clears 5,
            // which the influence rule above grants only while rapport stays above 3.
            var cifRuleId = edm.AddActionRule(new ActionRuleDTO
            {
                Action = Name.BuildName("Speak([cs],[ns],[m],Neutral)"),
                Target = Name.BuildName("[x]"),
                Priority = Name.BuildName("9"),
                Layer = Name.BuildName("-"),
            });
            edm.AddRuleCondition(cifRuleId, "DialogueState([x]) = [cs]");
            // The exchange keeps a variable target, but the condition names the
            // counterparty concretely: an unbound [x] resolves to no possible
            // target inside the Volition dynamic property, so the rule never fires.
            edm.AddRuleCondition(cifRuleId, "Volition(GiveMetal, Start, Player, *) >= 5");
            edm.AddRuleCondition(cifRuleId, "ValidDialogue([cs],[ns],[m],Neutral) = True");

            var genericRuleId = edm.AddActionRule(new ActionRuleDTO
            {
                Action = Name.BuildName("Speak([cs],[ns],[m],Neutral)"),
                Target = Name.BuildName("[x]"),
                Priority = Name.BuildName("1"),
                Layer = Name.BuildName("-"),
            });
            edm.AddRuleCondition(genericRuleId, "DialogueState([x]) = [cs]");
            edm.AddRuleCondition(genericRuleId, "ValidDialogue([cs],[ns],[m],Neutral) = True");

            edm.Save();

            AddDialogue(iat, "s1", "s2", "Question", "Neutral", "What are you doing?");
            AddDialogue(iat, "s1", "s3", "Question", "Neutral", "How are you feeling?");
            AddDialogue(iat, "s2", "s4", "Answer", "Neutral", "Nothing special.");
            AddDialogue(iat, "s3", "s4", "Answer", "Neutral", "I am feeling great.");

            var speakEvent = Name.BuildName("Event(Action-End, [s], Speak([cs], [ns], [m], [st]), [t])");
            iat.WorldModel.addActionTemplate(speakEvent, 1);
            iat.WorldModel.AddActionEffect(speakEvent, new EffectDTO
            {
                PropertyName = Name.BuildName("DialogueState([s])"),
                NewValue = Name.BuildName("[ns]"),
                ObserverAgent = Name.BuildName("[t]"),
            });
            iat.WorldModel.AddActionEffect(speakEvent, new EffectDTO
            {
                PropertyName = Name.BuildName("DialogueState([t])"),
                NewValue = Name.BuildName("[ns]"),
                ObserverAgent = Name.BuildName("[s]"),
            });

            File.WriteAllText(Path.Combine(outDir, "scenario.json"), iat.ToJson());
            File.WriteAllText(Path.Combine(outDir, "assets.json"), iat.Assets.ToJson() ?? "[]");

            Console.WriteLine($"Wrote {scenarioName} (rapport={rapport}) to {Path.GetFullPath(outDir)}");
        }

        static void AddDialogue(IntegratedAuthoringToolAsset iat, string cs, string ns, string meaning, string style, string utterance)
        {
            iat.AddDialogAction(new DialogueStateActionDTO
            {
                CurrentState = cs,
                NextState = ns,
                Meaning = meaning,
                Style = style,
                Utterance = utterance,
            });
        }

        static ConditionSetDTO ConditionsFrom(params string[] conditions)
        {
            return new ConditionSetDTO { ConditionSet = conditions };
        }
    }
}
