### EDIT: tests/Feature/GrowthStallReactionTest.php
<<<<<<< SEARCH
        setAccountClock($base->subHours($hour));
        app(RecordAiScoreSamplesAction::class)->execute($playerId);
=======
        setAccountClock($base->subHours($hour));
        app(RecordAiScoreSamplesAction::class)->handle($playerId);
>>>>>>> REPLACE