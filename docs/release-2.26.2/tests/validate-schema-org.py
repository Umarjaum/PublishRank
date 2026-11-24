# Fetches every test URL and checks each JSON-LD node against the schema.org vocabulary bundled with the plugin:
# JSON parses; every @type is a schema.org class; every property exists and applies to the node's type (domain incl. ancestors);
# no superseded properties; every {"@id"} reference resolves inside the page graph; no empty values.
import json, re, sys, urllib.request, collections
V=json.load(open(__import__('os').path.join(__import__('os').path.dirname(__file__), '../../../assets/schema/vocabulary.json')))
classes=V['classes']; props=V['props']; sup=V['superseded']; enums=V['enums']
def anc(t, seen=None):
    seen=seen or set()
    if t in seen: return seen
    seen.add(t)
    for p in classes.get(t, []) or (V['types'].get(t,{}).get('parents',[])): anc(p, seen)
    return seen
enum_members=set(m for v in enums.values() for m in v)
U=json.load(open(__import__('os').path.join(__import__('tempfile').gettempdir(), 'schema_urls.json')))['urls']
issues=collections.defaultdict(list); typecount=collections.Counter(); pages_ok=0
def walk(node, path, ids, refs, key):
    if isinstance(node, list):
        for i,x in enumerate(node): walk(x, f'{path}[{i}]', ids, refs, key)
        return
    if not isinstance(node, dict): 
        if node in ('', None): issues[key].append(f'empty value at {path}')
        return
    if '@id' in node and len(node)==1: refs.append(node['@id']); return
    if '@id' in node: ids.add(node['@id'])
    types=node.get('@type'); types=[types] if isinstance(types,str) else (types or [])
    A=set()
    for t in types:
        typecount[t]+=1
        if t not in classes and t not in V['types']: issues[key].append(f'unknown type {t} at {path}')
        A|=anc(t)
    for p,v in node.items():
        if p.startswith('@'): continue
        if p.endswith('-input') or p.endswith('-output'): continue  # schema.org Action input/output shorthand (PropertyValueSpecification)
        if p in sup: issues[key].append(f'superseded property {p} (use {sup[p]}) on {types} at {path}')
        elif p not in props: issues[key].append(f'unknown property {p} on {types} at {path}')
        elif types and props[p]['d'] and not (set(props[p]['d']) & A): issues[key].append(f'property {p} not valid for {types} (domains {props[p]["d"][:4]}) at {path}')
        if v in ('', None, [], {}): issues[key].append(f'empty {p} at {path}')
        walk(v, f'{path}.{p}', ids, refs, key)
for key,url in U.items():
    try: html=urllib.request.urlopen(url, timeout=30).read().decode('utf-8','replace')
    except Exception as e: issues[key].append(f'fetch failed {e}'); continue
    blocks=re.findall(r'<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>', html, re.S)
    if not blocks: issues[key].append('no JSON-LD'); continue
    ids=set(); refs=[]
    for b in blocks:
        try: d=json.loads(b)
        except Exception as e: issues[key].append(f'invalid JSON: {e}'); continue
        nodes=d.get('@graph', [d]) if isinstance(d, dict) else d
        for i,n in enumerate(nodes): walk(n, f'graph[{i}]', ids, refs, key)
    for r in refs:
        if r not in ids and not r.startswith('http') : issues[key].append(f'dangling @id {r}')
        elif r not in ids and r.startswith(url.split('?')[0].rstrip('/')) and '#' in r: issues[key].append(f'unresolved local @id {r}')
    if not issues[key]: pages_ok+=1
print(f'pages: {len(U)}  clean: {pages_ok}  distinct types: {len(typecount)}')
print('types:', ', '.join(sorted(typecount)))
for k,v in issues.items():
    if v: print('\n##', k); [print('  -', x) for x in sorted(set(v))[:12]]
