#!/usr/bin/env python3
import json,os,subprocess,sys
from pathlib import Path
os.umask(0o077)
ROOT=Path(os.environ['FARM_LAB_OUTPUT']).resolve()
ROOT.mkdir(parents=True,exist_ok=True,mode=0o700)
ANDROID=Path(os.environ.get('FARM_LAB_ANDROID',str(Path(__file__).resolve().parents[1])))
ADB=os.environ.get('FARM_LAB_ADB','adb')
DEVICE=os.environ['FARM_LAB_SERIAL']
SOCKET=os.environ.get('FARM_LAB_SSH_SOCKET')
SSH=['ssh',*(['-S',SOCKET] if SOCKET else []),'-o','BatchMode=yes','-o','ProxyCommand=false',os.environ['FARM_LAB_SSH_HOST']]
STATE=ROOT/'run-private.json'
SERVER="sudo -nu popfarmstep5 env 'FARM_DSN=mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=poptracker_farm_test_step05;charset=utf8mb4' FARM_DB_USER=popfarmstep5 php /opt/poptracker-step05/lab.php"
def remote(data):
 p=subprocess.run([*SSH,SERVER],input=json.dumps(data),capture_output=True,text=True,check=True)
 return json.loads(p.stdout)
def adb(*args):return subprocess.check_output([ADB,'-s',DEVICE,*args],text=True)
def test(method,extra=None,death=False):
 cmd=['shell','am','instrument','-w','-e','class','cloud.poptracker.farm.NetworkBatchTest#'+method]
 for k,v in (extra or {}).items():cmd+=['-e',k,v]
 cmd+=['cloud.poptracker.farm.ipcpoc.test/androidx.test.runner.AndroidJUnitRunner']
 p=subprocess.run([ADB,'-s',DEVICE,*cmd],capture_output=True,text=True,timeout=170)
 (ROOT/(method+'.log')).write_text(p.stdout+p.stderr)
 if death:
  assert 'OK ('not in p.stdout,'Expected controlled process death'
 else:
  if 'OK (1 test)'not in p.stdout:raise RuntimeError(method+' failed; inspect its saved log')
 print(method,'controlled process death'if death else'PASS',flush=True)
def main():
 action=sys.argv[1]
 if action=='prepare':
  if STATE.exists():raise RuntimeError('A fresh output directory is required for a new enrollment')
  apk=ANDROID/'ipc-client/build/outputs/apk/androidTest/debug/ipc-client-debug-androidTest.apk'
  adb('install','-r',str(apk))
  issued=remote({'action':'issue'});STATE.write_text(json.dumps(issued));test('enrollControlledClient',{'enrollmentCode':issued['code']})
  queued=remote({'action':'queue','device_id':issued['device_id'],'fixtures':list(range(1,11))})
  (ROOT/'ten-queued.json').write_text(json.dumps(queued,indent=2));print('Queued',len(queued),'synthetic jobs')
 else:
  device=json.loads(STATE.read_text())['device_id']
  if action=='ten':test('tenJobsTraverseHttpsEngineContentProviderAndDatabaseReceipt')
  elif action=='recovery':
   remote({'action':'queue','device_id':device,'fixtures':[1]});test('prepareLostAcknowledgementRecovery')
   observed=subprocess.run([ADB,'-s',DEVICE,'shell','pidof','cloud.poptracker.farm.ipcpoc'],capture_output=True,text=True)
   print('Client PID after instrumentation',observed.stdout.strip() or 'already exited')
   adb('shell','am','force-stop','cloud.poptracker.farm.ipcpoc')
   test('deliverAfterClientProcessRestart')
  elif action=='resume-recovery':
   adb('shell','am','force-stop','cloud.poptracker.farm.ipcpoc')
   test('deliverAfterClientProcessRestart')
  elif action=='unknown':
   remote({'action':'queue','device_id':device,'fixtures':[2]});test('terminateClientAfterProviderReplyBeforeSavingResult',death=True)
   test('recoverUnknownExecutionWithoutCallingProviderAgain')
  elif action=='export':
   rows=remote({'action':'summary','device_id':device});(ROOT/'server-results.json').write_text(json.dumps(rows,indent=2))
   print('Server tasks',len(rows),'by status',{s:sum(r['status']==s for r in rows)for s in {r['status']for r in rows}})
  elif action=='revoke':print(remote({'action':'revoke','device_id':device}))
  else:raise ValueError('Unknown test stage')
if __name__=='__main__':main()
