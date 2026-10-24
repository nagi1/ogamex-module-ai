"""Policy server for `ai:sim --choice-policy=socket` (SocketChoicePolicy): one JSON line in, one out.

    python -m ogrl.serve --model runs/bc-model/model.onnx --socket /tmp/ogrl.sock
    python -m ogrl.serve --build-model storage/rl/build-model/model.onnx --socket /tmp/ogrl.sock   # building choices only

Request: {"key", "player", "kind", "state": [S], "cands": [[C] x K], "legal": [K], "buildable": [K], "teacher": i}
Answer:  {"index": i}  (a legal row; argmax by default, or a seeded sample with --temperature)
A build-order model (export_build_order) names the next thing to build among the buildable rows, payable now or not, and
the PHP side turns an unpayable pick into waiting. A kind no loaded model owns is answered with the planner's own row.
The PHP side falls back to the planner when the server is down, slow or answers an illegal row.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import socketserver
import threading

import numpy as np
import onnxruntime as ort

ort.preload_dlls()   # the CUDA libraries ship as pip packages next to torch


class Scorer:
    """One ONNX model with the schema it was trained on."""

    def __init__(self, model: str):
        options = ort.SessionOptions()
        options.intra_op_num_threads = 1
        # The GPU only: no CPU provider is listed, so a missing or broken CUDA stops the server instead of quietly serving from the CPU.
        self.session = ort.InferenceSession(model, options, providers=["CUDAExecutionProvider"])
        if self.session.get_providers()[0] != "CUDAExecutionProvider":
            raise RuntimeError("the model did not load on the GPU: " + str(self.session.get_providers()))
        schema_file = os.path.join(os.path.dirname(os.path.abspath(model)), "schema.json")
        if not os.path.exists(schema_file):
            raise RuntimeError(f"{schema_file} is missing: a model is served only with the schema it was trained on")
        with open(schema_file) as handle:
            schema = json.load(handle)
        self.version = int(schema["version"])
        self.widths = (len(schema["state"]), len(schema["candidate"]))
        self.moon_column = schema["state"].index("pl_is_moon")

    def logits(self, request: dict, mask: np.ndarray) -> np.ndarray:
        # A stale model must never play silently: the PHP side counts the refusal as a fallback to the planner.
        if "v" in request and int(request["v"]) != self.version:
            raise ValueError(f"encoder version {request['v']} but the model was trained on version {self.version}")
        if len(request["state"]) != self.widths[0] or any(len(row) != self.widths[1] for row in request["cands"]):
            raise ValueError(f"widths {len(request['state'])}/{len(request['cands'][0])} but the model expects {self.widths}")
        state = np.asarray(request["state"], dtype=np.float32)[None, :]
        cands = np.asarray(request["cands"], dtype=np.float32)[None, :, :]
        logits = self.session.run(["logits"], {"state": state, "cands": cands, "mask": mask[None, :]})[0][0]
        return np.where(mask, logits, -np.inf)


class Policy:
    def __init__(self, model: str | None, build_model: str | None, temperature: float, seed: int):
        self.bc = Scorer(model) if model else None
        self.build = Scorer(build_model) if build_model else None
        self.temperature = temperature
        self.seed = seed
        self.lock = threading.Lock()
        self.served = 0

    def choose(self, request: dict) -> int:
        if request.get("kind") == "building" and self.build is not None and request["state"][self.build.moon_column] < 0.5:
            return self.choose_build(request)
        if self.bc is None:
            return int(request.get("teacher", 0))
        mask = np.asarray(request["legal"], dtype=bool)
        with self.lock:
            logits = self.bc.logits(request, mask)
            self.served += 1
        if self.temperature <= 0:
            return int(np.argmax(logits))
        # Seeded by the choice's own key, so a seeded simulation replays the same samples.
        draw = int(hashlib.sha256(f"{self.seed}:{request.get('key', '')}".encode()).hexdigest()[:8], 16) / 0xFFFFFFFF
        z = (logits - logits[np.isfinite(logits)].max()) / self.temperature
        p = np.exp(np.where(np.isfinite(z), z, -np.inf))
        cdf = np.cumsum(p / p.sum())
        return int(min(np.searchsorted(cdf, draw), len(cdf) - 1))

    def choose_build(self, request: dict) -> int:
        """The next thing to build among the rows the host would take now or once the planet can pay; row 0 (waiting) is not one."""
        mask = np.asarray(request["buildable"], dtype=bool)
        mask[0] = False
        if not mask.any():
            return 0
        with self.lock:
            logits = self.build.logits(request, mask)
            self.served += 1
        return int(np.argmax(logits))


def main(argv: list[str] | None = None) -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--model", help="model.onnx from train_bc: answers every kind the build-order model does not own")
    ap.add_argument("--build-model", help="model.onnx from export_build_order: answers the building choices of planets")
    ap.add_argument("--socket", required=True)
    ap.add_argument("--temperature", type=float, default=0.0)
    ap.add_argument("--seed", type=int, default=0)
    args = ap.parse_args(argv)

    if not (args.model or args.build_model):
        ap.error("give --model, --build-model or both")
    policy = Policy(args.model, args.build_model, args.temperature, args.seed)

    class Handler(socketserver.StreamRequestHandler):
        def handle(self) -> None:
            for line in self.rfile:
                try:
                    index = policy.choose(json.loads(line))
                    answer = {"index": index}
                except Exception as error:  # the PHP side treats any non-answer as "use the planner"
                    answer = {"error": str(error)}
                self.wfile.write((json.dumps(answer) + "\n").encode())
                self.wfile.flush()

    if os.path.exists(args.socket):
        os.unlink(args.socket)
    with socketserver.ThreadingUnixStreamServer(args.socket, Handler) as server:
        os.chmod(args.socket, 0o666)
        print(f"serving {args.model or '-'} and {args.build_model or '-'} on {args.socket}", flush=True)
        server.serve_forever()


if __name__ == "__main__":
    main()
