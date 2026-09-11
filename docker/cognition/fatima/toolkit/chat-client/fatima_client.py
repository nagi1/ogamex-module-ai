"""Thin wrapper around the FAtiMA HTTP Server's REST API."""
import requests


class FatimaClient:
    def __init__(self, base_url="http://localhost:8000"):
        self.base_url = base_url.rstrip("/")

    def _url(self, path):
        return f"{self.base_url}{path}"

    def create_scenario(self, scenario_json: str, assets_json: str) -> str:
        resp = requests.post(
            self._url("/scenarios"),
            json={"Scenario": scenario_json, "Assets": assets_json},
        )
        resp.raise_for_status()
        return resp.json()

    def delete_scenario(self, scenario_name: str) -> str:
        resp = requests.delete(self._url(f"/scenarios/{scenario_name}"))
        resp.raise_for_status()
        return resp.json()

    def create_instance(self, scenario_name: str) -> int:
        resp = requests.post(self._url(f"/scenarios/{scenario_name}/instances"), json={})
        resp.raise_for_status()
        return resp.json()

    def get_characters(self, scenario_name: str, instance_id: int):
        resp = requests.get(
            self._url(f"/scenarios/{scenario_name}/instances/{instance_id}/characters")
        )
        resp.raise_for_status()
        return resp.json()

    def get_emotions(self, scenario_name: str, instance_id: int, character: str):
        resp = requests.get(
            self._url(
                f"/scenarios/{scenario_name}/instances/{instance_id}/characters/{character}/emotions"
            )
        )
        resp.raise_for_status()
        return resp.json()

    def get_beliefs(self, scenario_name: str, instance_id: int, character: str):
        resp = requests.get(
            self._url(
                f"/scenarios/{scenario_name}/instances/{instance_id}/characters/{character}/beliefs"
            )
        )
        resp.raise_for_status()
        return resp.json()

    def get_decisions(self, scenario_name: str, instance_id: int, character: str):
        resp = requests.get(
            self._url(
                f"/scenarios/{scenario_name}/instances/{instance_id}/characters/{character}/decisions"
            )
        )
        resp.raise_for_status()
        return resp.json()

    def perceive(self, scenario_name: str, instance_id: int, character: str, events: list[str]):
        resp = requests.post(
            self._url(
                f"/scenarios/{scenario_name}/instances/{instance_id}/characters/{character}/perceptions"
            ),
            json=events,
        )
        resp.raise_for_status()
        return resp.json()

    def set_belief(self, scenario_name: str, instance_id: int, character: str, belief: str, value: str):
        event = f"Event(Property-Change,{character},{belief},{value})"
        return self.perceive(scenario_name, instance_id, character, [event])

    def execute_actions(self, scenario_name: str, instance_id: int, actions: list[dict]):
        """Execute actions: every character perceives them (appraisal + memory)
        and the world model applies their effects. Each dict needs
        Subject / Action / Target keys."""
        resp = requests.post(
            self._url(f"/scenarios/{scenario_name}/instances/{instance_id}/actions"),
            json=actions,
        )
        resp.raise_for_status()
        return resp.json()

    def tick(self, scenario_name: str, instance_id: int, ticks: int = 1):
        """Advance simulation time; emotion intensities decay each tick."""
        resp = requests.post(
            self._url(f"/scenarios/{scenario_name}/instances/{instance_id}/tick"),
            json=ticks,
        )
        resp.raise_for_status()
        return resp.json()

    def get_memories(self, scenario_name: str, instance_id: int, character: str):
        resp = requests.get(
            self._url(
                f"/scenarios/{scenario_name}/instances/{instance_id}/characters/{character}/memories"
            )
        )
        resp.raise_for_status()
        return resp.json()
