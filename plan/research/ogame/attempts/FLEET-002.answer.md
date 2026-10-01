### EDIT: app/Domain/Decision/CandidateActionFactory.php
<<<<<<< SEARCH
            AiCandidateActionType::Transfer, AiCandidateActionType::Recycle => [0.4, 0.3, 0.0, 0.0],
=======
            AiCandidateActionType::Transfer => [0.4, 0.3, 0.0, 0.0],
            // A field beside an own body is free income the harvest carries back with nothing
            // risked: the same economy pressure as the mine that would otherwise be queued, and a
            // step safer than that build, so a decided harvest is not outranked by the next
            // building and the recycle the planner already planned is the one acted on.
            AiCandidateActionType::Recycle => [$resourceNeed, 0.3, 0.0, 0.0],
>>>>>>> REPLACE