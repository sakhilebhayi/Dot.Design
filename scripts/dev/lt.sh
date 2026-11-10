#!/bin/sh
# Run the library tests and print a compact failure summary.
cd "$(dirname "$0")/../.." && vendor/bin/phpunit tests/Unit/Library*.php 2>&1 | python3 -c "
import sys,json
raw=sys.stdin.read()
try:
    d=json.loads(raw)
except Exception:
    print(raw[-3000:]); sys.exit()
print(d['result'],d['tests'],'tests',d['passed'],'passed',d.get('failed',0),'failed')
seen=set()
for f in d.get('failures',[]):
    t=f['test'].split('::')[1]
    m=f['message'].split('\n')[0][:230]
    k=(t,m)
    if k in seen: continue
    seen.add(k)
    print('-',t,'|',m)
"
