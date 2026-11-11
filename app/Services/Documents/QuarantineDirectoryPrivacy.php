<?php

namespace App\Services\Documents;

use LogicException;
use Symfony\Component\Process\Process;

/** Verifies a provisioned private directory; never changes operating-system configuration/ACLs. */
final class QuarantineDirectoryPrivacy
{
    public static function assertPrivate(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $mode = fileperms($path);
            if ($mode === false || ($mode & 0077) !== 0) {
                throw new LogicException('Quarantine root must exclude group/other filesystem access.');
            }

            return;
        }
        $shell = getenv('SystemRoot').'/System32/WindowsPowerShell/v1.0/powershell.exe';
        $script = <<<'PS'
$ErrorActionPreference='Stop'
$sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
$rules=(Get-Acl -LiteralPath $env:QEP_PRIVATE_DIRECTORY).Access
foreach($rule in $rules){
 if($rule.AccessControlType -eq 'Allow' -and $rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value -notin @($sid,'S-1-5-18')){exit 3}
}
Write-Output 'PRIVATE'
PS;
        $process = new Process([$shell, '-NoLogo', '-NoProfile', '-NonInteractive', '-Command', $script], env: ['QEP_PRIVATE_DIRECTORY' => $path]);
        $process->setTimeout(10);
        $process->run();
        if (! $process->isSuccessful() || trim($process->getOutput()) !== 'PRIVATE') {
            throw new LogicException('Quarantine root requires owner/SYSTEM-only Windows ACLs.');
        }
    }
}
