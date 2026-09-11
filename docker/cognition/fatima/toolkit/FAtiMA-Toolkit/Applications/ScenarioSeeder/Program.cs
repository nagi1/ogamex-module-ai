using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using ActionLibrary.DTOs;
using EmotionalAppraisal.DTOs;
using GAIPS.Rage;
using IntegratedAuthoringTool;
using IntegratedAuthoringTool.DTOs;
using WellFormedNames;
using WorldModel.DTOs;

namespace ScenarioSeeder
{
    // Reproduces the running example of the FAtiMA Toolkit paper
    // (Mascarenhas et al. 2022, ACM TiiS 12(1), Article 8):
    //  - dialogue tree of Figure 2 / Tables 2 and 3 (d1..d5, states s1..s4)
    //  - the two decision rules of Section 5 (generic Speak + Rude override on negative mood)
    //  - the appraisal rule example of Section 3.7 (Smile appraised via RapportLevel)
    class Program
    {
        static void Main(string[] args)
        {
            string outDir = args.Length > 0 ? args[0] : ".";
            Directory.CreateDirectory(outDir);

            var iat = new IntegratedAuthoringToolAsset
            {
                ScenarioName = "ChatDemo",
                ScenarioDescription = "Running example of the FAtiMA Toolkit paper (Fig. 2, Tables 2-3, Sections 3.7 and 5)"
            };

            var assets = new AssetStorage();
            iat.Assets = assets;

            iat.AddNewCharacter((Name)"John");
            var john = iat.Characters.First(c => c.CharacterName == (Name)"John");
            john.LoadAssociatedAssets(assets);

            // The paper's scenario: the character is in a negative mood (cf. Table 3
            // discussion; the repo's sample John.rpc also ships with Mood = -5).
            john.Mood = -5;
            john.UpdateBelief("DialogueState(Player)", "s1");
            // Belief used by the Section 3.7 appraisal rule.
            john.UpdateBelief("RapportLevel(SELF,Player)", "5");

            // --- Dialogue tree: Table 2 (d1..d4) + Table 3 (d5) -------------------
            // The paper's tables have no Meaning/Style columns; the rules only rely
            // on the Style symbol "Rude" for d5, so the rest is tagged Neutral.
            AddDialogue(iat, "s1", "s2", "Question", "Neutral", "What are you doing?");        // d1 (player)
            AddDialogue(iat, "s1", "s3", "Question", "Neutral", "How are you feeling?");       // d2 (player)
            AddDialogue(iat, "s2", "s4", "Answer",   "Neutral", "Nothing special.");           // d3 (John)
            AddDialogue(iat, "s3", "s4", "Answer",   "Neutral", "I am feeling great.");        // d4 (John)
            AddDialogue(iat, "s3", "s4", "Answer",   "Rude",    "None of your business.");     // d5 (John)

            // --- Appraisal rule: the Section 3.7 example ---------------------------
            // Event(Action-End,[x],Smile,SELF) with Desirability = [d],
            // where [d] is retrieved from the belief RapportLevel(SELF,[x]).
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

            // --- Decision rules: the two rules of Section 5 ------------------------
            var edm = john.m_emotionalDecisionMakingAsset;

            // Generic rule (priority 1): handles the basic dialogue tree.
            // The paper writes this rule with a free style variable [s]; here the
            // style is pinned to Neutral so that Rude lines are spoken exclusively
            // through the override rule below (otherwise d4/d5 would tie on priority).
            var genericRuleId = edm.AddActionRule(new ActionRuleDTO
            {
                Action = Name.BuildName("Speak([cs],[ns],[m],Neutral)"),
                Target = Name.BuildName("[x]"),
                Priority = Name.BuildName("1"),
                Layer = Name.BuildName("-"),
            });
            edm.AddRuleCondition(genericRuleId, "DialogueState([x]) = [cs]");
            edm.AddRuleCondition(genericRuleId, "ValidDialogue([cs],[ns],[m],Neutral) = True");

            // Rude override (priority 2): speak in a "Rude" style when mood is negative.
            var rudeRuleId = edm.AddActionRule(new ActionRuleDTO
            {
                Action = Name.BuildName("Speak([cs],[ns],[m],Rude)"),
                Target = Name.BuildName("[x]"),
                Priority = Name.BuildName("2"),
                Layer = Name.BuildName("-"),
            });
            edm.AddRuleCondition(rudeRuleId, "DialogueState([x]) = [cs]");
            edm.AddRuleCondition(rudeRuleId, "ValidDialogue([cs],[ns],[m],Rude) = True");
            edm.AddRuleCondition(rudeRuleId, "Mood(SELF) < 0");

            edm.Save();

            // --- World model: a Speak action advances the dialogue state -----------
            // (Section 6: action consequences are configured in the World Editor.)
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
            File.WriteAllText(Path.Combine(outDir, "dialogues.json"), SerializeDialogues(iat));

            Console.WriteLine("Seed files written to " + Path.GetFullPath(outDir));
        }

        static void AddDialogue(IntegratedAuthoringToolAsset iat, string cs, string ns, string meaning, string style, string utterance)
        {
            iat.AddDialogAction(new DialogueStateActionDTO
            {
                CurrentState = cs,
                NextState = ns,
                Meaning = meaning,
                Style = style,
                Utterance = utterance
            });
        }

        static Conditions.DTOs.ConditionSetDTO ConditionsFrom(params string[] conditions)
        {
            return new Conditions.DTOs.ConditionSetDTO { ConditionSet = conditions };
        }

        static string SerializeDialogues(IntegratedAuthoringToolAsset iat)
        {
            // Emit a small JSON array of the dialogue table for the Python client to read,
            // independent of the internal serialization format used by scenario.json.
            var all = iat.GetAllDialogueActions().ToArray();
            var sb = new System.Text.StringBuilder();
            sb.Append("[\n");
            for (int i = 0; i < all.Length; i++)
            {
                var d = all[i];
                sb.Append("  {");
                sb.Append("\"CurrentState\":\"" + d.CurrentState + "\",");
                sb.Append("\"NextState\":\"" + d.NextState + "\",");
                sb.Append("\"Meaning\":\"" + d.Meaning + "\",");
                sb.Append("\"Style\":\"" + d.Style + "\",");
                sb.Append("\"Utterance\":\"" + d.Utterance.Replace("\"", "\\\"") + "\"");
                sb.Append("}");
                sb.Append(i < all.Length - 1 ? ",\n" : "\n");
            }
            sb.Append("]\n");
            return sb.ToString();
        }
    }
}
