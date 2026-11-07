### EDIT: app/Domain/Decision/QueueableBuildingPlanner.php
<<<<<<< SEARCH
        $steps = [];
        foreach ($passes as $candidates) {
            foreach ($planets as $planet) {
                if (count($steps) >= $limit) {
                    return array_values($steps);
                }

                $steps = $this->withStep($steps, $planet, $profile, $candidates);
            }
        }
=======
        $steps = [];
        foreach ($passes as $pass) {
            foreach ($planets as $planet) {
                if (count($steps) >= $limit) {
                    return array_values($steps);
                }

                $candidates = $pass($planet);
                $steps = $this->withStep($steps, $planet, $profile, $candidates);

                // The host runs the build queue and the lab side by side, so the login's one
                // technology goes beside the planet's building instead of competing with it for the
                // planet's single step.
                if (count($steps) >= $limit) {
                    return array_values($steps);
                }

                $steps = $this->withResearchStep($steps, $planet, $candidates);
            }
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
=======
    /**
     * @param array<int|string, QueueableBuilding|QueueableResearch> $steps
     * @param list<BuildCandidate> $candidates
     * @return array<int|string, QueueableBuilding|QueueableResearch>
     */
    private function withStep(array $steps, PlanetService $planet, AiProfile $profile, array $candidates): array
    {
        if (isset($steps[$planet->getPlanetId()])) {
            return $steps;
        }

        // The lab is one queue for the account: once a technology is taken, the other planets build.
>>>>>>> REPLACE

### EDIT: app/Domain/Decision/QueueableBuildingPlanner.php
<<<<<<< SEARCH
    /**
     * The first candidate this planet can actually queue, or null when none of them is legal.
     *
     * @param list<BuildCandidate> $candidates
     */
    private function firstQueueable(PlanetService $planet, AiProfile $profile, array $candidates): QueueableBuilding|QueueableResearch|null
=======
    /**
     * The login's one technology, offered beside the planet's building because the host runs the build
     * queue and the lab side by side: a player with stock for both fills both, and a planet whose step
     * was a building would otherwise leave the lab idle for the whole login. The lab is one queue for
     * the account, so the first planet whose laboratory can carry it does.
     *
     * @param array<int|string, QueueableBuilding|QueueableResearch> $steps
     * @param list<BuildCandidate> $candidates
     * @return array<int|string, QueueableBuilding|QueueableResearch>
     */
    private function withResearchStep(array $steps, PlanetService $planet, array $candidates): array
    {
        if ($this->hasResearchStep($steps)) {
            return $steps;
        }

        $research = $this->firstQueueableResearch($planet, $candidates, $this->spentHere($steps, $planet));
        if ($research !== null) {
            $steps['research'] = $research;
        }

        return $steps;
    }

    /**
     * What the planet's step already commits of its stock, so the technology beside it is chosen
     * against what is left after the building.
     *
     * @param array<int|string, QueueableBuilding|QueueableResearch> $steps
     */
    private function spentHere(array $steps, PlanetService $planet): ?Resources
    {
        $taken = $steps[$planet->getPlanetId()] ?? null;
        if (!$taken instanceof QueueableBuilding) {
            return null;
        }

        return ObjectService::getObjectPrice(ObjectService::getObjectById($taken->buildingId)->machine_name, $planet);
    }

    /** @param array<int|string, QueueableBuilding|QueueableResearch> $steps */
    private function hasResearchStep(array $steps): bool
    {
        return array_filter($steps, static fn (object $step): bool => $step instanceof QueueableResearch) !== [];
    }

    /**
     * The first technology among this planet's candidates it can actually queue, or null when none of
     * them is a technology. Same gates as a building, asked the way the research page asks them.
     *
     * @param list<BuildCandidate> $candidates
     */
    private function firstQueueableResearch(PlanetService $planet, array $candidates, ?Resources $alreadySpent): ?QueueableResearch
    {
        foreach ($candidates as $candidate) {
            if (ObjectService::getObjectById($candidate->buildingId)->type !== GameObjectType::Research) {
                continue;
            }

            $research = $this->queueableResearch($planet, $candidate, $alreadySpent);
            if ($research !== null) {
                return $research;
            }
        }

        return null;
    }

    /**
     * The first candidate this planet can actually queue, or null when none of them is legal.
     *
     * @param list<BuildCandidate> $candidates
     */
    private function firstQueueable(PlanetService $planet, AiProfile $profile, array $candidates): QueueableBuilding|QueueableResearch|null
>>>>>>> REPLACE

### EDIT: app/Domain/Decision/QueueableBuildingPlanner.php
<<<<<<< SEARCH
    private function queueableResearch(PlanetService $planet, BuildCandidate $candidate): ?QueueableResearch
    {
        $machineName = ObjectService::getObjectById($candidate->buildingId)->machine_name;

        // A technology already in research anywhere on the account is not offered again: the host
        // would take the row and then cancel it, so the capability is withheld instead of the
        // decision being spent on a refusal.
        if ($this->researchQueueService->activeResearchQueueItemCount($planet->getPlayer(), $candidate->buildingId) > 0) {
            return null;
        }

        $queueable = !$this->researchQueueService->retrieveQueue($planet)->isQueueFull()
            && ObjectService::objectRequirementsMetWithQueue($machineName, ($planet->getPlayer()?->getResearchLevel($machineName) ?? 0) + 1, $planet)
            && $planet->hasResources($this->withReserve($planet, ObjectService::getObjectPrice($machineName, $planet), ReserveFloor::RESEARCH_HOURS));
=======
    private function queueableResearch(PlanetService $planet, BuildCandidate $candidate, ?Resources $alreadySpent = null): ?QueueableResearch
    {
        $machineName = ObjectService::getObjectById($candidate->buildingId)->machine_name;

        // A technology already in research anywhere on the account is not offered again: the host
        // would take the row and then cancel it, so the capability is withheld instead of the
        // decision being spent on a refusal.
        if ($this->researchQueueService->activeResearchQueueItemCount($planet->getPlayer(), $candidate->buildingId) > 0) {
            return null;
        }

        $price = ObjectService::getObjectPrice($machineName, $planet);

        // The lab and the build queue draw from one balance, so a technology that only fit before the
        // planet's building was chosen is a row the host cancels the moment it starts.
        if ($alreadySpent !== null) {
            $price = new Resources(
                $price->metal->get() + $alreadySpent->metal->get(),
                $price->crystal->get() + $alreadySpent->crystal->get(),
                $price->deuterium->get() + $alreadySpent->deuterium->get(),
                $price->energy->get(),
            );
        }

        $queueable = !$this->researchQueueService->retrieveQueue($planet)->isQueueFull()
            && ObjectService::objectRequirementsMetWithQueue($machineName, ($planet->getPlayer()?->getResearchLevel($machineName) ?? 0) + 1, $planet)
            && $planet->hasResources($this->withReserve($planet, $price, ReserveFloor::RESEARCH_HOURS));
>>>>>>> REPLACE