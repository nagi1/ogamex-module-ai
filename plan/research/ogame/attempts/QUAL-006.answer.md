### EDIT: app/Domain/Decision/QueueableBuildingPlanner.php
<<<<<<< SEARCH
        $passes = $this->passes($profile);

        $steps = [];
        foreach ($passes as $candidates) {
            foreach ($planets as $planet) {
                if (count($steps) >= $limit) {
                    return array_values($steps);
                }

                $steps = $this->withStep($steps, $planet, $profile, $candidates);
            }
        }

        return array_values($steps);
    }
=======
        $passes = $this->passes($profile);

        $steps = [];
        $research = null;
        foreach ($passes as $pass) {
            foreach ($planets as $planet) {
                if (count($steps) >= $limit) {
                    return $this->ordered($steps, $research);
                }

                // One candidate list per planet per pass, read by both of its queues: the building
                // competes with everything that planet could do, the technology only with the
                // account's laboratory.
                $candidates = $pass($planet);
                $steps = $this->withStep($steps, $planet, $candidates);
                $research = $this->withResearch($research, $planet, $candidates);
            }
        }

        return $this->ordered($steps, $research);
    }
>>>>>>> REPLACE

### EDIT: app/Domain/Decision/QueueableBuildingPlanner.php
<<<<<<< SEARCH
    /**
     * @param array<int, QueueableBuilding|QueueableResearch> $steps
     * @param callable(PlanetService): list<BuildCandidate> $pass
     * @return array<int, QueueableBuilding|QueueableResearch>
     */
    private function withStep(array $steps, PlanetService $planet, AiProfile $profile, callable $pass): array
    {
        if (isset($steps[$planet->getPlanetId()])) {
            return $steps;
        }

        $candidates = $pass($planet);

        // The lab is one queue for the account: once a technology is taken, the other planets build.
        if (array_filter($steps, static fn (object $taken): bool => $taken instanceof QueueableResearch) !== []) {
            $candidates = array_values(array_filter(
                $candidates,
                static fn (BuildCandidate $candidate): bool => ObjectService::getObjectById($candidate->buildingId)->type !== GameObjectType::Research,
            ));
        }

        $step = $this->firstQueueable($planet, $profile, $candidates);
        if ($step === null) {
            return $steps;
        }

        $steps[$planet->getPlanetId()] = $step;

        return $steps;
    }

    /**
     * The first candidate this planet can actually queue, or null when none of them is legal.
     *
     * @param list<BuildCandidate> $candidates
     */
    private function firstQueueable(PlanetService $planet, AiProfile $profile, array $candidates): QueueableBuilding|QueueableResearch|null
    {
        foreach ($candidates as $candidate) {
            // Which queue takes a step is the host's object type, not this module's opinion: the
            // chain hands over prerequisites, and a technology among them is research.
            if (ObjectService::getObjectById($candidate->buildingId)->type === GameObjectType::Research) {
                $research = $this->queueableResearch($planet, $candidate);
                if ($research === null) {
                    continue;
                }

                return $research;
            }

            $planetId = $planet->getPlanetId();
            if (!$this->canQueue($planet, $candidate)) {
                continue;
            }

            return app()->makeWith(QueueableBuilding::class, [
                'planetId' => $planetId,
                'buildingId' => $candidate->buildingId,
                'reason' => $candidate->reason,
            ]);
        }

        return null;
    }
=======
    /**
     * @param array<int, QueueableBuilding> $steps
     * @param list<BuildCandidate> $candidates
     * @return array<int, QueueableBuilding>
     */
    private function withStep(array $steps, PlanetService $planet, array $candidates): array
    {
        if (isset($steps[$planet->getPlanetId()])) {
            return $steps;
        }

        $step = $this->firstQueueable($planet, $candidates);
        if ($step === null) {
            return $steps;
        }

        $steps[$planet->getPlanetId()] = $step;

        return $steps;
    }

    /**
     * The technology the laboratory on this planet could start now, the one the account already took,
     * or null when there is none.
     *
     * The lab is one queue for the whole account, while each planet has a build queue of its own, so a
     * login fills both: a planet whose economy always has a next mine would otherwise keep the lab
     * empty forever.
     *
     * @param list<BuildCandidate> $candidates
     */
    private function withResearch(?QueueableResearch $research, PlanetService $planet, array $candidates): ?QueueableResearch
    {
        if ($research !== null) {
            return $research;
        }

        foreach ($candidates as $candidate) {
            if (ObjectService::getObjectById($candidate->buildingId)->type !== GameObjectType::Research) {
                continue;
            }

            $queueable = $this->queueableResearch($planet, $candidate);
            if ($queueable !== null) {
                return $queueable;
            }
        }

        return null;
    }

    /**
     * The first building candidate this planet can actually queue, or null when none of them is legal.
     *
     * @param list<BuildCandidate> $candidates
     */
    private function firstQueueable(PlanetService $planet, array $candidates): ?QueueableBuilding
    {
        foreach ($candidates as $candidate) {
            // Which queue takes a step is the host's object type, not this module's opinion: the chain
            // hands over prerequisites, and a technology among them belongs to the lab, not to this
            // planet's build queue.
            if (ObjectService::getObjectById($candidate->buildingId)->type === GameObjectType::Research) {
                continue;
            }

            if (!$this->canQueue($planet, $candidate)) {
                continue;
            }

            return app()->makeWith(QueueableBuilding::class, [
                'planetId' => $planet->getPlanetId(),
                'buildingId' => $candidate->buildingId,
                'reason' => $candidate->reason,
            ]);
        }

        return null;
    }

    /**
     * The account's steps with its single technology ahead of them: the lab has a queue of its own, so
     * a caller that asks for the first step sees the technology a planet's next mine would hide.
     *
     * @param array<int, QueueableBuilding> $steps
     * @return list<QueueableBuilding|QueueableResearch>
     */
    private function ordered(array $steps, ?QueueableResearch $research): array
    {
        return array_values($research === null ? $steps : [$research, ...$steps]);
    }
>>>>>>> REPLACE

### EDIT: app/Domain/Decision/QueueableBuildingPlanner.php
<<<<<<< SEARCH
 * is what keeps a full warehouse on one colony from waiting behind a routine mine on the homeworld.
=======
 * is what keeps a full warehouse on one colony from waiting behind a routine mine on the homeworld.
 * A technology the chain wants is a step of its own, ahead of them all, because the laboratory is one
 * queue for the account: a planet whose economy always has a next mine would otherwise keep the lab
 * empty forever.
>>>>>>> REPLACE