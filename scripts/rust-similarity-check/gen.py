import sys, json, random, types
sys.modules['cbrkit']=types.SimpleNamespace(retrieval=types.SimpleNamespace(build=lambda f:f))
import os
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'docker', 'cognition', 'cbrkit'))
import retriever
random.seed(7)
keys=['object_id','planet_id','target_level','resource','amount','flag']
def val(k):
    r=random.random()
    if r<0.08: return None
    if k in('object_id','planet_id'): return random.choice([1,2,3,3.0,"3",14])
    if k=='resource': return random.choice(["metal","crystal",5])
    if k=='flag': return random.choice([True,False,1,0])
    return random.choice([0,1,5,10,10.5,100,-3,2.5e3,"x"])
cases={}
for i in range(300):
    cases[i+1]={k:val(k) for k in random.sample(keys,random.randint(0,6))}
queries={}
for q in range(200):
    queries["q%d"%q]={k:val(k) for k in random.sample(keys,random.randint(0,5))}
exp={n:{str(i):retriever.feature_similarity(c,qq) for i,c in cases.items()} for n,qq in queries.items()}
json.dump({"casebase":cases,"queries":queries,"expected":exp},open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'data.json'),'w'))
print(len(cases),len(queries))
