#!/usr/bin/env python3
"""Run real PM5 plus production PHAR in an isolated, disposable server directory."""
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

p = argparse.ArgumentParser(description=__doc__)
p.add_argument('--php', required=True, type=Path)
p.add_argument('--pmmp', required=True, type=Path)
p.add_argument('--port', type=int, default=19350)
args = p.parse_args()
root = Path(__file__).resolve().parent.parent
php, pmmp = str(args.php.resolve(strict=True)), str(args.pmmp.resolve(strict=True))
subprocess.run([php, '-n', '-d', 'phar.readonly=0', str(root/'tools/build.php')], check=True)
(root/'work').mkdir(exist_ok=True)
testphar=root/'work/AmyxAnvilTests.phar'
script = '$p=new Phar($argv[1]);$p->buildFromDirectory($argv[2]);$p->setStub("<?php __HALT_COMPILER();");$p->setSignatureAlgorithm(Phar::SHA256);'
if testphar.exists(): testphar.unlink()
subprocess.run([php,'-n','-d','phar.readonly=0','-r',script,str(testphar),str(root/'tests/server')],check=True)
server=Path(tempfile.mkdtemp(prefix='server-test-',dir=root/'work'))
plugins=server/'plugins';plugins.mkdir()
for source in (root/'build/AmyxAnvil.phar',testphar): shutil.copy2(source,plugins/source.name)
(server/'server.properties').write_text(f'server-ip=127.0.0.1\nserver-port={args.port}\nserver-portv6={args.port+1}\nenable-query=off\nlevel-type=FLAT\n')
env=os.environ.copy();env['AMYXANVIL_RUN_TESTS']='1'
with (server/'test.log').open('w') as log:
    run=subprocess.run([php,'-n','-d','zend.assertions=-1',pmmp,'--no-wizard','--disable-ansi',f'--data={server}',f'--plugins={plugins}'],cwd=server,env=env,stdout=log,stderr=subprocess.STDOUT,timeout=90)
result=server/'amyxanvil-results.json'
if run.returncode or not result.exists(): raise SystemExit(f'Server failed: {server}/test.log')
results=json.loads(result.read_text());print(json.dumps(results,ensure_ascii=False));print(f'Logs: {server}')
if results['failures'] or results['passed']<20: raise SystemExit(1)
