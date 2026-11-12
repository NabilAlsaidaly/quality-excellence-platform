param(
    [ValidateSet('Prepare','Browser','Demo','Cleanup')][string]$Mode='Prepare',
    [Parameter(Mandatory=$true)][string]$Php,
    [string]$PlaywrightCli='',
    [ValidatePattern('^browser-[0-9]{2}$')][string]$RunName='browser-01',
    [switch]$InspectOnly
)
$ErrorActionPreference='Stop'
if($Mode -eq 'Demo'){$InspectOnly=$true}
$project=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
$private=Join-Path $project ('.local-tools/reports/issue62/review-maturity/'+$RunName)
$manifest=Join-Path $private 'connection.json'
function Protect-Directory([string]$Path) {
    $acl=New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true,$false)
    $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User
    $acl.SetOwner($sid)
    foreach($principal in @($sid,[Security.Principal.SecurityIdentifier]::new('S-1-5-18'))){$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($principal,'FullControl','ContainerInherit,ObjectInherit','None','Allow'))}
    Set-Acl -LiteralPath $Path -AclObject $acl
}
function Save-State { [IO.File]::WriteAllText($manifest,($state|ConvertTo-Json -Depth 8)) }
function Assert-Private {
    if([IO.Path]::GetFullPath($private) -ne [IO.Path]::GetFullPath((Join-Path $project ('.local-tools/reports/issue62/review-maturity/'+$RunName))) -or ((Get-Item -LiteralPath $private).Attributes -band [IO.FileAttributes]::ReparsePoint)){throw 'Unsafe private fixture path'}
    if($state.namespace -ne 'qep-issue62-review-maturity-browser' -or $state.run_name -ne $RunName -or $state.fixture_id -notmatch '^[a-f0-9]{32}$' -or $state.database -ne 'quality_platform_ci' -or $state.http_host -ne '127.0.0.6'){throw 'Unsafe fixture manifest'}
}
function Assert-Server {
    Assert-Private
    $container=docker inspect $state.mysql_id|ConvertFrom-Json
    if($LASTEXITCODE -ne 0 -or $container.Config.Labels.'qep.test.issue' -ne '62' -or $container.Config.Labels.'qep.test.session' -ne $state.fixture_id -or $container.NetworkSettings.Ports.'3306/tcp'[0].HostIp -ne '127.0.0.1' -or $container.NetworkSettings.Ports.'3306/tcp'[0].HostPort -ne $state.db_port){throw 'Refuse unrelated database service'}
    $identity=docker exec $state.mysql_id mysql --defaults-extra-file=/tmp/issue62-workflow.cnf --protocol=TCP '--host=127.0.0.1' --batch --skip-column-names quality_platform_ci -e 'SELECT DATABASE(), @@server_uuid, VERSION();'
    if($LASTEXITCODE -ne 0){throw 'Cannot verify database identity'}
    $parts=$identity.Split("`t")
    if($parts[0] -ne 'quality_platform_ci' -or $parts[1] -ne $state.server_uuid -or !$parts[2].StartsWith('8.4.')){throw 'Refuse wrong database/server/version'}
}
function Cleanup-Owned {
    Assert-Private
    if($state.server_pid){
        $process=Get-CimInstance Win32_Process -Filter ('ProcessId='+$state.server_pid) -ErrorAction SilentlyContinue
        if($process){if($process.ExecutablePath -ne $Php -or !$process.CommandLine.Contains((Join-Path $PSScriptRoot 'Router.php')) -or !$process.CommandLine.Contains($state.http_host+':'+$state.http_port)){throw 'Refuse unrelated PID'};Stop-Process -Id $state.server_pid}
    }
    if($state.mysql_id){
        $container=docker inspect $state.mysql_id|ConvertFrom-Json
        if($LASTEXITCODE -ne 0 -or $container.Config.Labels.'qep.test.issue' -ne '62' -or $container.Config.Labels.'qep.test.session' -ne $state.fixture_id){throw 'Refuse unowned container'}
        foreach($mount in $container.Mounts){if($mount.Type -eq 'volume'){$owners=@(docker ps -aq --filter ('volume='+$mount.Name));if($owners.Count -ne 1 -or !$state.mysql_id.StartsWith($owners[0])){throw 'Refuse shared volume'}}}
        docker stop $state.mysql_id|Out-Null
        if($LASTEXITCODE -ne 0){throw 'Owned container stop failed'}
        docker rm -v $state.mysql_id|Out-Null
        if($LASTEXITCODE -ne 0){throw 'Owned container removal failed'}
    }
    foreach($name in @('connection.json','fixture.json','mysql-root-password','mysql-runtime-password','mysql-client.cnf','rendered-step.js')){if(Test-Path -LiteralPath (Join-Path $private $name)){Remove-Item -LiteralPath (Join-Path $private $name)}}
    foreach($name in @('sessions','cache','locks','views','tmp','files','.playwright-cli')){
        $dir=[IO.Path]::GetFullPath((Join-Path $private $name))
        if(!$dir.StartsWith($private+[IO.Path]::DirectorySeparatorChar)){throw 'Unsafe cleanup target'}
        if(Test-Path -LiteralPath $dir){if(((Get-Item -LiteralPath $dir).Attributes -band [IO.FileAttributes]::ReparsePoint) -or @(Get-ChildItem -LiteralPath $dir -Recurse -Force|Where-Object{$_.Attributes -band [IO.FileAttributes]::ReparsePoint}).Count){throw 'Refuse redirected cache tree'};Remove-Item -LiteralPath $dir -Recurse -Force}
    }
    [IO.File]::WriteAllText((Join-Path $private 'cleanup.json'),(@{fixture=$state.fixture_id;ownedResourcesRemoved=$true;utc=[DateTime]::UtcNow.ToString('o')}|ConvertTo-Json))
}
if(!(Test-Path -LiteralPath $Php)){throw 'Use existing installed PHP executable'}
$Php=(Get-Item -LiteralPath $Php).FullName
if($Mode -eq 'Prepare'){
    if(Test-Path -LiteralPath $private){throw 'Preserve existing runtime/evidence; do not adopt it'}
    New-Item -ItemType Directory -Path $private|Out-Null
    Protect-Directory $private
    foreach($name in @('sessions','cache','locks','views','tmp','files')){New-Item -ItemType Directory -Path (Join-Path $private $name)|Out-Null}
    $fixtureId=[Guid]::NewGuid().ToString('N')
    $state=[pscustomobject]@{namespace='qep-issue62-review-maturity-browser';run_name=$RunName;fixture_id=$fixtureId;database='quality_platform_ci';mysql_id='';db_port=0;server_uuid='';http_host='127.0.0.6';http_port=0;server_pid=0;app_key='base64:'+([Convert]::ToBase64String([Guid]::NewGuid().ToByteArray()+[Guid]::NewGuid().ToByteArray()));runtime_password=[Guid]::NewGuid().ToString('N');source_hashes=@{}}
    $rootPassword=[Guid]::NewGuid().ToString('N')
    [IO.File]::WriteAllText((Join-Path $private 'mysql-root-password'),$rootPassword)
    [IO.File]::WriteAllText((Join-Path $private 'mysql-runtime-password'),$state.runtime_password)
    [IO.File]::WriteAllText((Join-Path $private 'mysql-client.cnf'),("[client]`nuser=root`npassword="+$rootPassword+"`n"))
    Save-State
    try {
        docker image inspect mysql:8.4.13 *> $null
        if($LASTEXITCODE -ne 0){throw 'Use the existing audited MySQL8.4 image'}
        $argsList=@('run','-d','--name',('qep-issue62-workflow-'+$fixtureId),'--label','qep.test.issue=62','--label',('qep.test.session='+$fixtureId),'-p','127.0.0.1::3306','-e','MYSQL_DATABASE=quality_platform_ci','-e','MYSQL_USER=workflow_fixture','-e','MYSQL_ROOT_PASSWORD_FILE=/run/secrets/mysql-root-password','-e','MYSQL_PASSWORD_FILE=/run/secrets/mysql-runtime-password')
        foreach($name in @('mysql-root-password','mysql-runtime-password','mysql-client.cnf')){$argsList+=@('--mount',('type=bind,src='+(Join-Path $private $name)+',dst=/run/secrets/'+$name+',readonly'))}
        $state.mysql_id=& docker @argsList 'mysql:8.4.13'
        if($LASTEXITCODE -ne 0){throw 'Owned database creation failed'}
        Save-State
        docker exec $state.mysql_id install -m 600 /run/secrets/mysql-client.cnf /tmp/issue62-workflow.cnf
        if($LASTEXITCODE -ne 0){throw 'Owned MySQL client setup failed'}
        $saved=$ErrorActionPreference
        try {$ErrorActionPreference='Continue';for($i=0;$i -lt 60;$i++){$identity=docker exec $state.mysql_id mysql --defaults-extra-file=/tmp/issue62-workflow.cnf --protocol=TCP '--host=127.0.0.1' --batch --skip-column-names quality_platform_ci -e 'SELECT @@server_uuid;' 2>$null;if($LASTEXITCODE -eq 0){break};Start-Sleep -Milliseconds 500}}finally{$ErrorActionPreference=$saved}
        if($LASTEXITCODE -ne 0){throw 'Owned MySQL readiness failed'}
        $state.server_uuid=$identity
        $container=docker inspect $state.mysql_id|ConvertFrom-Json
        $state.db_port=[int]$container.NetworkSettings.Ports.'3306/tcp'[0].HostPort
        Save-State
        Assert-Server
        Write-Output 'Owned MySQL8.4 identity verified; empty disposable test schema prepared.'
    } catch {if($state.mysql_id){Cleanup-Owned};throw}
    exit
}
$state=Get-Content -LiteralPath $manifest -Raw -Encoding UTF8|ConvertFrom-Json
if($Mode -eq 'Cleanup'){Cleanup-Owned;exit}
Assert-Server
if(!(Test-Path -LiteralPath $PlaywrightCli)){throw 'Use the existing installed Playwright CLI'}
$env:QEP_REVIEW_MATURITY_FIXTURE=$state.fixture_id;$env:QEP_REVIEW_MATURITY_RUN=$RunName
function Invoke-Fixture([string]$Operation,[string]$Argument=''){
    $previousOptIn=[Environment]::GetEnvironmentVariable('MYSQL_TESTS_ENABLED','Process')
    $previousUuid=[Environment]::GetEnvironmentVariable('MVP_FIXTURE_MYSQL_UUID','Process')
    try {
        if($Operation -eq 'setup'){
            Assert-Server
            # Scope the approved persistence DDL opt-in to this verified owned
            # setup child only; never inherit it into the HTTP runtime.
            $env:MYSQL_TESTS_ENABLED='1'
            $env:MVP_FIXTURE_MYSQL_UUID=$state.server_uuid
        }
        & $Php -d ('sys_temp_dir='+(Join-Path $private 'tmp')) (Join-Path $PSScriptRoot 'Fixture.php') $Operation $Argument
        $fixtureExitCode=$LASTEXITCODE
    } finally {
        [Environment]::SetEnvironmentVariable('MYSQL_TESTS_ENABLED',$previousOptIn,'Process')
        [Environment]::SetEnvironmentVariable('MVP_FIXTURE_MYSQL_UUID',$previousUuid,'Process')
    }
    if($fixtureExitCode -ne 0){throw ('Owned fixture operation failed: '+$Operation)}
}
function Invoke-Journey([string]$Phase,[hashtable]$Data){
    $script=[IO.File]::ReadAllText((Join-Path $PSScriptRoot 'Journey.js')).Replace('/* PRIVATE_FIXTURE */ null',($Data|ConvertTo-Json -Depth 8 -Compress)).Replace('/* JOURNEY_PHASE */ null',($Phase|ConvertTo-Json -Compress))
    $file=Join-Path $private 'rendered-step.js'
    [IO.File]::WriteAllText($file,$script)
    try {
        $output=& $PlaywrightCli ('-s='+$state.browser_session) run-code --filename $file
        $joined=$output -join "`n"
        $match=[regex]::Match($joined,'(?s)### Result\s*(.*?)\s*### Ran')
        if(!$match.Success){
            $errorText=[regex]::Match($joined,'(?s)### Error\s*(.*?)(?:### Ran|### Page|$)').Groups[1].Value
            foreach($account in $Data.accounts.Values){$errorText=$errorText.Replace($account.password,'[private]')}
            [IO.File]::WriteAllText((Join-Path $private 'sanitized-error.log'),$errorText)
            throw ('Browser phase failed: '+$Phase+'; private redacted diagnostic retained.')
        }
        $result=$match.Groups[1].Value|ConvertFrom-Json
        if(!$result.passed){throw ('Journey phase did not pass: '+$Phase)}
        return $result
    } finally {if(Test-Path -LiteralPath $file){Remove-Item -LiteralPath $file}}
}
if(!$state.server_pid){
    $listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Parse('127.0.0.6'),0)
    $listener.Start();$state.http_port=$listener.LocalEndpoint.Port;$listener.Stop()
    $source=@('app','database/migrations','config','routes','bootstrap','resources/views','tests/Support','tests/Browser') | ForEach-Object {
        Get-ChildItem -LiteralPath (Join-Path $project $_) -Recurse -File | Where-Object { $_.Extension -in @('.php','.js','.ps1') } | ForEach-Object { $_.FullName.Substring($project.Length+1).Replace('\','/') }
    }
    $source+=@('composer.lock','public/build/manifest.json')
    $state.source_hashes=@{}
    foreach($file in $source){$state.source_hashes[$file]=(Get-FileHash -LiteralPath (Join-Path $project $file)).Hash.ToLowerInvariant()}
    $state|Add-Member -NotePropertyName browser_session -NotePropertyValue ('review-maturity-'+$state.fixture_id) -Force
    Save-State
    Invoke-Fixture 'setup'
    $router=Join-Path $PSScriptRoot 'Router.php'
    $arguments='-d "sys_temp_dir='+(Join-Path $private 'tmp')+'" -d "upload_tmp_dir='+(Join-Path $private 'tmp')+'" -d upload_max_filesize=32M -d post_max_size=34M -d display_errors=0 -d log_errors=0 -S '+$state.http_host+':'+$state.http_port+' -t "'+(Join-Path $project 'public')+'" "'+$router+'"'
    $process=Start-Process -FilePath $Php -ArgumentList $arguments -WorkingDirectory $project -WindowStyle Hidden -RedirectStandardOutput (Join-Path $private 'server.stdout.log') -RedirectStandardError 'NUL' -PassThru
    $state.server_pid=$process.Id
    Save-State
}
$ready=$false
for($attempt=0;$attempt -lt 15;$attempt++){
    try {
        $response=Invoke-WebRequest -Uri ('http://'+$state.http_host+':'+$state.http_port+'/institution/login') -UseBasicParsing -TimeoutSec 5
        if($response.StatusCode -eq 200 -and $response.Headers['X-Qep-Review-Maturity-Fixture'] -eq $state.fixture_id){$ready=$true;break}
    } catch {}
    Start-Sleep -Milliseconds 200
}
if(!$ready){throw 'Owned HTTP fixture identity/readiness failed; retain evidence and run scoped Cleanup.'}
if($InspectOnly){Write-Output ('Owned TEST_ONLY review/maturity fixture ready at http://'+$state.http_host+':'+$state.http_port+'/institution/login');exit}
$browserStarted=$false
Push-Location $private
try {
    $f=Get-Content -LiteralPath (Join-Path $private 'fixture.json') -Raw -Encoding UTF8|ConvertFrom-Json
    $accounts=@{};foreach($a in $f.accounts.PSObject.Properties){$accounts[$a.Name]=$a.Value}
    $artifacts=Join-Path $project ('.local-tools/output/playwright/issue62/review-maturity/'+$RunName)
    if(Test-Path -LiteralPath $artifacts){throw 'Preserve existing browser evidence; no overwrite'}
    New-Item -ItemType Directory -Path $artifacts|Out-Null;Protect-Directory $artifacts
    $data=@{base='http://'+$state.http_host+':'+$state.http_port;accounts=$accounts;approach=$f.approach;stages=$f.stages;foreign=$f.foreign;artifacts=$artifacts.Replace('\','/')}
    $null=& $PlaywrightCli ('-s='+$state.browser_session) open ($data.base+'/institution/login') --headed
    if($LASTEXITCODE -ne 0){throw 'Owned browser start failed'}
    $browserStarted=$true
    $results=@(Invoke-Journey 'workflow' $data)
    [IO.File]::WriteAllText((Join-Path $private 'journey-results.json'),($results|ConvertTo-Json -Depth 8))
    Invoke-Fixture 'verify'
    $receipt=@{head=(git -C $project rev-parse HEAD);classification='TEST_ONLY';scenario='REVIEW_MATURITY_LOCAL_001';policyReplaced=$false;sourceHashes=$state.source_hashes;journeySHA256=(Get-FileHash (Join-Path $PSScriptRoot 'Journey.js')).Hash;browserAssertions=(($results|ForEach-Object{$_.checks.Count}|Measure-Object -Sum).Sum);utc=[DateTime]::UtcNow.ToString('o')}
    [IO.File]::WriteAllText((Join-Path $private 'browser-receipt.json'),($receipt|ConvertTo-Json -Depth 8))
    Write-Output ('Actual review/maturity browser/Policy/MySQL PASS: '+$receipt.browserAssertions+' browser assertions.')
} finally {
    if($browserStarted){$null=& $PlaywrightCli ('-s='+$state.browser_session) close}
    Pop-Location
    Cleanup-Owned
}
