<?php

namespace Tests\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/** Provision only this test's new, empty, random disk; no normal storage/global ACL changes. */
trait PrivateIntakeTestDisk
{
    private function privateDisk(): FilesystemAdapter
    {
        $disk = Storage::fake('issue68-'.bin2hex(random_bytes(8)));
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($disk->path(''), 0700);

            return $disk;
        }
        $script = <<<'PS'
$ErrorActionPreference='Stop'
$acl=New-Object Security.AccessControl.DirectorySecurity
$acl.SetAccessRuleProtection($true,$false)
$sid=[Security.Principal.WindowsIdentity]::GetCurrent().User
$acl.SetOwner($sid)
foreach($p in @($sid,[Security.Principal.SecurityIdentifier]::new('S-1-5-18'))){$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($p,'FullControl','ContainerInherit,ObjectInherit','None','Allow'))}
Set-Acl -LiteralPath $env:QEP_TEST_PRIVATE_DIRECTORY -AclObject $acl
PS;
        $process = new Process([getenv('SystemRoot').'/System32/WindowsPowerShell/v1.0/powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive', '-Command', $script],
            env: ['QEP_TEST_PRIVATE_DIRECTORY' => $disk->path('')]);
        $process->setTimeout(10);
        $process->mustRun();

        return $disk;
    }
}
