"""Policy server for `ai:sim --choice-policy=socket` (SocketChoicePolicy): one JSON line in, one out.

    python -m ogrl.serve --model runs/bc-model/model.onnx --socket /tmp/ogrl.sock

Request: {"key", "player", "research", "state": [S], "cands": [[C] x K], "legal": [K]}
Answer:  {"index": i}  (always a legal row; argmax by default, or a seeded sample with --temperature)
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


class Policy:
    def __init__(self, model: str, temperature: float, seed: int):
        options = ort.SessionOptions()
        options.intra_op_num_threads = 1
        self.session = ort.InferenceSession(model, options, providers=["CPUExecutionProvider"])
        self.temperature = temperature
        self.seed = seed
        self.lock = threading.Lock()
        self.served = 0

    def choose(self, request: dict) -> int:
        state = np.asarray(request["state"], dtype=np.float32)[None, :]
        cands = np.asarray(request["cands"], dtype=np.float32)[None, :, :]
        legal = np.asarray(request["legal"], dtype=bool)[None, :]
        with self.lock:
            logits = self.session.run(["logits"], {"state": state, "cands": cands, "mask": legal})[0][0]
            self.served += 1
        logits = np.where(legal[0], logits, -np.inf)
        if self.temperature <= 0:
            return int(np.argmax(logits))
        # Seeded by the choice's own key, so a seeded simulation replays the same samples.
        draw = int(hashlib.sha256(f"{self.seed}:{request.get('key', '')}".encode()).hexdigest()[:8], 16) / 0xFFFFFFFF
        z = (logits - logits[np.isfinite(logits)].max()) / self.temperature
        p = np.exp(np.where(np.isfinite(z), z, -np.inf))
        cdf = np.cumsum(p / p.sum())
        return int(min(np.searchsorted(cdf, draw), len(cdf) - 1))


def main(argv: list[str] | None = None) -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--model", required=True, help="model.onnx from train_bc")
    ap.add_argument("--socket", required=True)
    ap.add_argument("--temperature", type=float, default=0.0)
    ap.add_argument("--seed", type=int, default=0)
    args = ap.parse_args(argv)

    policy = Policy(args.model, args.temperature, args.seed)

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
        print(f"serving {args.model} on {args.socket}", flush=True)
        server.serve_forever()


if __name__ == "__main__":
    main()
