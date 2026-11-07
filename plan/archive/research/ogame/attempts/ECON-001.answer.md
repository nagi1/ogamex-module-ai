### EDIT: app/Actions/ScheduleAiIntentAction.php
<<<<<<< SEARCH
        // A player refills the build queues and the lab every login before turning to the shipyard or
        // the fleet; choosing a raid or a ship must not leave nine planets idle until the next session.
        // A transfer or a save is priced against the very balance a queue would spend, and a session
        // that placed no order has nothing to spend on: neither may refill the queues.
        $economySteps = $this->fillsEconomy($type) && !in_array($type, [AiCandidateActionType::Transfer, AiCandidateActionType::FleetSave, AiCandidateActionType::DoNothing], true) && $this->economyOffered($trace)
            ? $this->fillQueues($profile, $sessionWorkItem, ':economy')
            : 0;
=======
        // A player refills the build queues and the lab on every login, whatever else the login was
        // for: a raid, a probe, a ferry or a save does not leave the planets idle until the next
        // session. The queue orders are written first, so a fleet intent is priced against what the
        // queues leave instead of the other way round; only a Build or Research selection skips this,
        // because the step it already decided is the queue order itself.
        $economySteps = !$this->isQueueOrder($type) && $this->economyOffered($trace)
            ? $this->fillQueues($profile, $sessionWorkItem, ':economy')
            : 0;
>>>>>>> REPLACE

### EDIT: app/Actions/ScheduleAiIntentAction.php
<<<<<<< SEARCH
    /**
     * Whether the economy is filled before the selected action. Transfers and saves move the stock
     * away, so a building priced against it would be cancelled; Build and Research fill it themselves;
     * an idle session is a player who did not log in to play.
     */
    private function fillsEconomy(AiCandidateActionType $type): bool
    {
        return match ($type) {
            AiCandidateActionType::QueueUnits,
            AiCandidateActionType::Spy,
            AiCandidateActionType::Raid,
            AiCandidateActionType::Expedition,
            AiCandidateActionType::Recycle,
            AiCandidateActionType::Recall,
            AiCandidateActionType::Phalanx,
            AiCandidateActionType::ThrottleMine,
            AiCandidateActionType::Colonize => true,
            default => false,
        };
    }
=======
    /**
     * Whether the selected action is itself the login's queue order. Build and Research are: the step
     * they decided is the step the queues take, under the session's own key. Every other selection --
     * a fleet errand, a ferry, a save, a quiet logout -- leaves the queues exactly as empty as it found
     * them, so the login fills them first and the fleet order is sized against what is left.
     */
    private function isQueueOrder(AiCandidateActionType $type): bool
    {
        return match ($type) {
            AiCandidateActionType::Build, AiCandidateActionType::Research => true,
            default => false,
        };
    }
>>>>>>> REPLACE