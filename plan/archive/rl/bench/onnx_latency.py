"""Latency of the ~0.29 M-parameter candidate scorer in ONNX Runtime (CPU, 1 thread). pip install onnx onnxruntime numpy"""
import numpy as np, onnx, onnxruntime as ort, time
from onnx import helper, TensorProto, numpy_helper
rng = np.random.default_rng(0); inits = []; nodes = []
def dense(x, name, i, o, relu=True):
    inits.append(numpy_helper.from_array((rng.standard_normal((i, o)) * 0.05).astype(np.float32), name + 'W'))
    inits.append(numpy_helper.from_array(np.zeros(o, np.float32), name + 'b'))
    nodes.append(helper.make_node('MatMul', [x, name + 'W'], [name + 'm'])); nodes.append(helper.make_node('Add', [name + 'm', name + 'b'], [name + 'a']))
    if not relu: return name + 'a'
    nodes.append(helper.make_node('Relu', [name + 'a'], [name + 'r'])); return name + 'r'
s = dense(dense('state', 's1', 100, 256), 's2', 256, 256); c = dense('cand', 'c1', 30, 128)
inits += [numpy_helper.from_array(np.array([0], np.int64), 'ax0'), numpy_helper.from_array(np.array([256], np.int64), 'w256')]
nodes += [helper.make_node('Shape', ['cand'], ['cs']), helper.make_node('Gather', ['cs', 'ax0'], ['k'], axis=0),
          helper.make_node('Concat', ['k', 'w256'], ['shp'], axis=0), helper.make_node('Expand', [s, 'shp'], ['sb']),
          helper.make_node('Concat', ['sb', c], ['sc'], axis=1)]
out = dense(dense(dense('sc', 'h1', 384, 256), 'h2', 256, 128), 'o', 128, 1, relu=False)
v = dense(dense(s, 'v1', 256, 256), 'v2', 256, 1, relu=False)
g = helper.make_graph(nodes, 'scorer', [helper.make_tensor_value_info('state', TensorProto.FLOAT, [1, 100]), helper.make_tensor_value_info('cand', TensorProto.FLOAT, ['K', 30])],
                      [helper.make_tensor_value_info(out, TensorProto.FLOAT, ['K', 1]), helper.make_tensor_value_info(v, TensorProto.FLOAT, [1, 1])], inits)
m = helper.make_model(g, opset_imports=[helper.make_opsetid('', 17)]); m.ir_version = 10; onnx.save(m, 'scorer.onnx')
print('params %.3f M' % (sum(np.prod(t.dims) for t in inits if t.data_type == TensorProto.FLOAT) / 1e6))
so = ort.SessionOptions(); so.intra_op_num_threads = 1; sess = ort.InferenceSession('scorer.onnx', so)
for K in (8, 32, 128):
    st = rng.standard_normal((1, 100)).astype(np.float32); cd = rng.standard_normal((K, 30)).astype(np.float32)
    for _ in range(50): sess.run(None, {'state': st, 'cand': cd})
    t = time.perf_counter()
    for _ in range(3000): sess.run(None, {'state': st, 'cand': cd})
    print('K=%3d: %.1f us/decision' % (K, (time.perf_counter() - t) / 3000 * 1e6))
