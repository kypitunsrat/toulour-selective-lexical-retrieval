from pathlib import Path
import hashlib,json
root=Path(__file__).resolve().parents[1]
m=json.loads((root/'MANIFEST.json').read_text())
for x in m:
 p=root/x['path'];assert p.stat().st_size==x['bytes'],x['path']
 assert hashlib.sha256(p.read_bytes()).hexdigest()==x['sha256'],x['path']
print(json.dumps({'status':'PASS','files':len(m)}))
