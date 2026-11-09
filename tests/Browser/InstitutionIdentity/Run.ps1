param(
    [Parameter(Mandatory=$true)][string]$Php,
    [Parameter(Mandatory=$true)][string]$PlaywrightCli,
    [switch]$PrepareOnly,
    [switch]$ContinuePrepared,
    [switch]$CleanupOnly
)
$ErrorActionPreference='Stop'
$project=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
$private=Join-Path $project '.local-tools/reports/issue53'
$artifacts=Join-Path $project '.local-tools/output/playwright/issue53'
$manifest=Join-Path $private 'connection.json'
$browserStarted=$false
$state=$null

function Assert-OwnedDirectory([string]$Path,[string]$Expected) {
    if([IO.Path]::GetFullPath($Path) -ne [IO.Path]::GetFullPath($Expected) -or ((Get-Item -LiteralPath $Path).Attributes -band [IO.FileAttributes]::ReparsePoint)){throw 'Unsafe owned directory'}
}
function Save-State { [IO.File]::WriteAllText($manifest,($state|ConvertTo-Json -Depth 6)) }
function Protect-Directory([string]$Path) {
    $acl=New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true,$false)
    $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User
    $acl.SetOwner($sid)
    foreach($principal in @($sid,[Security.Principal.SecurityIdentifier]::new('S-1-5-18'))){$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($principal,'FullControl','ContainerInherit,ObjectInherit','None','Allow'))}
    Set-Acl -LiteralPath $Path -AclObject $acl
}
function Cleanup-OwnedResources {
    Assert-OwnedDirectory $private (Join-Path $project '.local-tools/reports/issue53')
    if($state.namespace -ne 'qep-issue53-identity'){throw 'Refuse unknown fixture namespace'}
    if($state.server_pid){
        $process=Get-CimInstance Win32_Process -Filter ('ProcessId='+$state.server_pid) -ErrorAction SilentlyContinue
        if($process){if($process.ExecutablePath -ne $Php -or !$process.CommandLine.Contains((Join-Path $PSScriptRoot 'Router.php')) -or !$process.CommandLine.Contains($state.http_host+':'+$state.http_port)){throw 'Refuse reused server PID'};Stop-Process -Id $state.server_pid}
    }
    if($state.mysql_id){
        $container=docker inspect $state.mysql_id|ConvertFrom-Json
        if($LASTEXITCODE -ne 0 -or $container.Config.Labels.'qep.test.issue' -ne '53' -or $container.Config.Labels.'qep.test.session' -ne $state.session){throw 'Refuse unowned container'}
        foreach($mount in $container.Mounts){if($mount.Type -eq 'volume'){$owners=@(docker ps -aq --filter ('volume='+$mount.Name));if($owners.Count -ne 1 -or !$state.mysql_id.StartsWith($owners[0])){throw 'Refuse shared volume'}}}
        docker stop $state.mysql_id|Out-Null
        if($LASTEXITCODE -ne 0){throw 'Owned container stop failed'}
        docker rm -v $state.mysql_id|Out-Null
        if($LASTEXITCODE -ne 0){throw 'Owned container removal failed'}
    }
    foreach($name in @('fixture.json','connection.json','reset-0.txt','reset-1.txt','expired-reset.txt','mysql-root-password','mysql-runtime-password','mysql-client.cnf','rendered-step.js')){$path=[IO.Path]::GetFullPath((Join-Path $private $name));if(!$path.StartsWith($private+[IO.Path]::DirectorySeparatorChar)){throw 'Unsafe private file path'};if(Test-Path -LiteralPath $path){Remove-Item -LiteralPath $path}}
    foreach($name in @('sessions','cache','cache-locks','views','tmp')){
        $dir=Join-Path $private $name
        if(Test-Path -LiteralPath $dir){Assert-OwnedDirectory $dir (Join-Path $private $name);if(@(Get-ChildItem -LiteralPath $dir -Recurse -Force|Where-Object{$_.Attributes -band [IO.FileAttributes]::ReparsePoint}).Count){throw 'Refuse redirected cache child'};Remove-Item -LiteralPath $dir -Recurse -Force}
    }
    [IO.File]::WriteAllText((Join-Path $private 'cleanup.json'),(@{session=$state.session;ownedResourcesRemoved=$true;utc=[DateTime]::UtcNow.ToString('o')}|ConvertTo-Json))
}
function Invoke-Fixture([string]$Mode) {
    & $Php -d ('sys_temp_dir='+(Join-Path $private 'tmp')) (Join-Path $PSScriptRoot 'Fixture.php') $Mode
    if($LASTEXITCODE -ne 0){throw ('Owned fixture phase failed: '+$Mode)}
}
function Invoke-Journey([string]$Phase,[hashtable]$Data) {
    $template=[IO.File]::ReadAllText((Join-Path $PSScriptRoot 'Journey.js'))
    $rendered=$template.Replace('/* PRIVATE_FIXTURE */ null',($Data|ConvertTo-Json -Depth 5 -Compress)).Replace('/* JOURNEY_PHASE */ null',($Phase|ConvertTo-Json -Compress))
    $file=Join-Path $private 'rendered-step.js'
    [IO.File]::WriteAllText($file,$rendered)
    try {
        $output=& $PlaywrightCli ('-s='+$state.browser_session) run-code --filename $file
        $joined=$output -join "`n"
        $match=[regex]::Match($joined,'(?s)### Result\s*(.*?)\s*### Ran')
        if(!$match.Success){
            $errorText=[regex]::Match($joined,'(?s)### Error\s*(.*?)(?:### Ran|### Page|$)').Groups[1].Value
            foreach($secret in @($Data.accounts[0].password,$Data.accounts[1].password,$Data.newPassword,$Data.resetUrl,$Data.expiredUrl)){if($secret){$errorText=$errorText.Replace($secret,'[private]')}}
            $errorText=[regex]::Replace($errorText,'https?://[^\s]+/reset/[^\s]+','[private reset URL]')
            [IO.File]::WriteAllText((Join-Path $private 'sanitized-error.log'),$errorText)
            throw ('Browser phase failed: '+$Phase+'; sanitized local diagnostic saved.')
        }
        $result=$match.Groups[1].Value|ConvertFrom-Json
        if(!$result.passed){throw ('Browser phase did not pass: '+$Phase)}
        return $result
    } finally {if(Test-Path -LiteralPath $file){Remove-Item -LiteralPath $file}}
}

if(!(Test-Path -LiteralPath $Php) -or !(Test-Path -LiteralPath $PlaywrightCli)){throw 'Use existing installed PHP and Playwright CLI; no package installation is performed'}
if($CleanupOnly -or $ContinuePrepared){
    Assert-OwnedDirectory $private (Join-Path $project '.local-tools/reports/issue53')
    $state=Get-Content -LiteralPath $manifest -Raw -Encoding UTF8|ConvertFrom-Json
    if($state.namespace -ne 'qep-issue53-identity'){throw 'Wrong existing fixture namespace'}
    $env:QEP_IDENTITY_JOURNEY=$state.session
    if($CleanupOnly){Cleanup-OwnedResources;exit}
} else {
    if((Test-Path -LiteralPath $private) -or (Test-Path -LiteralPath $artifacts)){throw 'Preserve existing artifacts; use an explicit owned continuation/cleanup'}
    New-Item -ItemType Directory -Path $private,$artifacts|Out-Null
    Protect-Directory $private
    Protect-Directory $artifacts
    foreach($sub in @('sessions','cache','cache-locks','views','tmp')){New-Item -ItemType Directory -Path (Join-Path $private $sub)|Out-Null}
    $id=[Guid]::NewGuid().ToString('N').Substring(0,16)
    $listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Parse('127.0.0.3'),0)
    $listener.Start();$httpPort=$listener.LocalEndpoint.Port;$listener.Stop()
    $state=[pscustomobject]@{namespace='qep-issue53-identity';session=$id;mysql_id='';database='quality_platform_ci';db_host='127.0.0.1';db_port=0;server_uuid='';http_host='127.0.0.3';http_port=$httpPort;runtime_password=[Guid]::NewGuid().ToString('N');app_key='base64:'+([Convert]::ToBase64String([Guid]::NewGuid().ToByteArray()+[Guid]::NewGuid().ToByteArray()));source_hashes=@{};server_pid=0;browser_session='identity53-'+$id}
    $env:QEP_IDENTITY_JOURNEY=$id
    $env:APP_ENV='testing'
    $rootPassword=[Guid]::NewGuid().ToString('N')
    [IO.File]::WriteAllText((Join-Path $private 'mysql-root-password'),$rootPassword)
    [IO.File]::WriteAllText((Join-Path $private 'mysql-runtime-password'),$state.runtime_password)
    [IO.File]::WriteAllText((Join-Path $private 'mysql-client.cnf'),("[client]`nuser=root`npassword="+$rootPassword+"`n"))
    Save-State
    try {
        docker image inspect mysql:8.4.13 *> $null
        if($LASTEXITCODE -ne 0){throw 'Expected existing MySQL8.4 test image'}
        $dockerArgs=@('run','-d','--name',('qep-issue53-identity-'+$id),'--label','qep.test.issue=53','--label',('qep.test.session='+$id),'-p','127.0.0.1::3306','-e','MYSQL_DATABASE=quality_platform_ci','-e','MYSQL_USER=identity_journey','-e','MYSQL_ROOT_PASSWORD_FILE=/run/secrets/mysql-root-password','-e','MYSQL_PASSWORD_FILE=/run/secrets/mysql-runtime-password')
        foreach($file in @('mysql-root-password','mysql-runtime-password','mysql-client.cnf')){$dockerArgs+=@('--mount',('type=bind,src='+(Join-Path $private $file)+',dst=/run/secrets/'+$file+',readonly'))}
        $state.mysql_id=(& docker @dockerArgs 'mysql:8.4.13')
        if($LASTEXITCODE -ne 0){throw 'Dedicated test container creation failed'}
        Save-State
        docker exec $state.mysql_id install -m 600 /run/secrets/mysql-client.cnf /tmp/issue53.cnf
        if($LASTEXITCODE -ne 0){throw 'Owned test client preparation failed'}
        $identity=$null
        $savedErrorPreference=$ErrorActionPreference
        try {
            $ErrorActionPreference='Continue' # A not-yet-ready socket is retryable; no SQL mutation is retried.
            for($n=0;$n -lt 40;$n++){$identity=docker exec $state.mysql_id mysql --defaults-extra-file=/tmp/issue53.cnf --batch --skip-column-names quality_platform_ci -e 'SELECT DATABASE(), @@server_uuid, VERSION();' 2>$null;if($LASTEXITCODE -eq 0){break};Start-Sleep -Milliseconds 500}
        } finally {$ErrorActionPreference=$savedErrorPreference}
        if($LASTEXITCODE -ne 0){throw 'Dedicated MySQL did not become ready'}
        $parts=$identity.Split("`t")
        if($parts[0] -ne 'quality_platform_ci' -or !$parts[2].StartsWith('8.4.')){throw 'Unsafe test database identity'}
        $container=docker inspect $state.mysql_id|ConvertFrom-Json
        if($container.Config.Labels.'qep.test.session' -ne $id -or $container.NetworkSettings.Ports.'3306/tcp'[0].HostIp -ne '127.0.0.1'){throw 'Wrong owned container binding'}
        $state.db_port=[int]$container.NetworkSettings.Ports.'3306/tcp'[0].HostPort
        $state.server_uuid=$parts[1]
        $source=@('routes/web.php','config/auth.php','config/authorization.php','bootstrap/providers.php','app/Models/User.php','app/Policies/ApproachPolicy.php','app/Http/Middleware/InstitutionSession.php','app/Http/Controllers/InstitutionEntryController.php','app/Actions/Identity/InstitutionEntry.php','app/Actions/Identity/InstitutionRecovery.php','app/Actions/Identity/BrowserAccountSession.php','app/Providers/Filament/InstitutionPanelProvider.php','tests/Browser/InstitutionIdentity/Environment.php','tests/Browser/InstitutionIdentity/Router.php','tests/Browser/InstitutionIdentity/Fixture.php')
        foreach($file in $source){$state.source_hashes[$file]=(Get-FileHash -LiteralPath (Join-Path $project $file) -Algorithm SHA256).Hash.ToLowerInvariant()}
        Save-State
        Push-Location $project
        try {Invoke-Fixture 'setup'} finally {Pop-Location}
        $router=Join-Path $PSScriptRoot 'Router.php'
        $arguments='-d "sys_temp_dir='+(Join-Path $private 'tmp')+'" -d display_errors=0 -d log_errors=0 -S '+$state.http_host+':'+$state.http_port+' -t "'+(Join-Path $project 'public')+'" "'+$router+'"'
        $process=Start-Process -FilePath $Php -ArgumentList $arguments -WorkingDirectory $project -WindowStyle Hidden -RedirectStandardOutput (Join-Path $private 'server.stdout.log') -RedirectStandardError 'NUL' -PassThru
        $state.server_pid=$process.Id
        Save-State
    } catch {if($state.mysql_id){Cleanup-OwnedResources};throw}
}

if($PrepareOnly){Write-Output ('Prepared isolated identity journey at http://'+$state.http_host+':'+$state.http_port+'/institution/login');exit}
try {
    $fixture=Get-Content -LiteralPath (Join-Path $private 'fixture.json') -Raw -Encoding UTF8|ConvertFrom-Json
    $data=@{base='http://'+$state.http_host+':'+$state.http_port;accounts=$fixture.accounts;newPassword=$fixture.new_password_a;artifacts=$artifacts.Replace('\','/')}
    Push-Location $private
    try {
        $null=& $PlaywrightCli ('-s='+$state.browser_session) open ($data.base+'/institution/login') --headed
        if($LASTEXITCODE -ne 0){throw 'Owned browser startup failed'}
        $browserStarted=$true
        $results=@(Invoke-Journey 'before-reset' $data)
        [IO.File]::WriteAllText((Join-Path $private 'journey-results.json'),($results|ConvertTo-Json -Depth 8))
        Invoke-Fixture 'expired-link'
        $data.resetUrl=[IO.File]::ReadAllText((Join-Path $private 'reset-0.txt'))
        $data.expiredUrl=[IO.File]::ReadAllText((Join-Path $private 'expired-reset.txt'))
        $results+=Invoke-Journey 'reset' $data
        [IO.File]::WriteAllText((Join-Path $private 'journey-results.json'),($results|ConvertTo-Json -Depth 8))
        Invoke-Fixture 'verify'
        Invoke-Fixture 'expire-sessions'
        $results+=Invoke-Journey 'expired-sessions' $data
        [IO.File]::WriteAllText((Join-Path $private 'journey-results.json'),($results|ConvertTo-Json -Depth 8))
        $receipt=@{applicationCommit=(git -C $project rev-parse HEAD);sourceHashes=$state.source_hashes;journeyScriptSHA256=(Get-FileHash -LiteralPath (Join-Path $PSScriptRoot 'Journey.js')).Hash;environment='testing';database='quality_platform_ci';actualServerVerified=$true;policiesReplaced=$false;grantsCreated=$false;phases=$results.Count;assertions=(($results|ForEach-Object{$_.checks.Count}|Measure-Object -Sum).Sum);utc=[DateTime]::UtcNow.ToString('o')}
        [IO.File]::WriteAllText((Join-Path $private 'run-receipt.json'),($receipt|ConvertTo-Json -Depth 8))
        Write-Output ('J01 browser phases PASS; '+(($results|ForEach-Object{$_.checks.Count}|Measure-Object -Sum).Sum)+' assertions; independent MySQL readback PASS.')
    } finally {Pop-Location}
} finally {
    if($browserStarted){$null=& $PlaywrightCli ('-s='+$state.browser_session) close}
    Cleanup-OwnedResources
}
