$ErrorActionPreference = 'Stop'
$base = 'http://mother.local:8080/aov/'
$php = 'C:\xampp\php\php.exe'
$env:AOV_PRIVATE_DIR = 'C:\xampp\aov-private'
$users = @()
try {
    foreach ($index in 1..3) {
        $name = 'AOVMail' + (Get-Random -Minimum 100000 -Maximum 999999)
        $password = [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(24))
        $env:AOV_MAIL_NAME = $name; $env:AOV_MAIL_PASSWORD = $password; $env:AOV_MAIL_IP = '2001:db8:2::' + (Get-Random -Minimum 100000 -Maximum 999999)
        $id = & $php -r '$_SERVER["REMOTE_ADDR"]=getenv("AOV_MAIL_IP"); require "C:/xampp/htdocs/aov/bootstrap.php"; $a=new Delight\Auth\Auth(aov_db()); $id=$a->registerWithUniqueUsername(strtolower(getenv("AOV_MAIL_NAME"))."@example.com",getenv("AOV_MAIL_PASSWORD"),getenv("AOV_MAIL_NAME")); aov_db()->prepare("INSERT INTO aov_profiles(user_id,terms_accepted_at) VALUES(?,?)")->execute([$id,time()]); echo $id;'
        if ($LASTEXITCODE -ne 0 -or !($id -match '^\d+$')) { throw 'Could not create mail test account' }
        if ($index -eq 3) {
            $env:AOV_MAIL_ADMIN_ID = [string]$id
            & $php -r 'require "C:/xampp/htdocs/aov/bootstrap.php"; (new Delight\Auth\Administration(aov_db()))->addRoleForUserById((int)getenv("AOV_MAIL_ADMIN_ID"),Delight\Auth\Role::ADMIN);'
            if ($LASTEXITCODE -ne 0) { throw 'Could not assign test administrator' }
        }
        $user = @{name=$name;id=[int]$id;session=$null;csrf=$null}
        $users += $user
        $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        $page = Invoke-WebRequest -Uri $base -WebSession $session -UseBasicParsing
        $state = ([regex]::Match($page.Content, '<script type="application/json" id="aov-state">([^<]+)</script>').Groups[1].Value | ConvertFrom-Json)
        $login = Invoke-RestMethod -Uri ($base+'api.php?action=login') -Method Post -WebSession $session -Headers @{'X-AOV-CSRF'=$state.csrf} -ContentType 'application/json' -Body (@{name=$name;password=$password}|ConvertTo-Json -Compress)
        $user.session = $session; $user.csrf = $login.csrf
    }
    $a=$users[0]; $b=$users[1]; $c=$users[2]
    $guest=Invoke-WebRequest -Uri ($base+'mail-api.php?action=list') -UseBasicParsing -SkipHttpErrorCheck
    if ($guest.StatusCode -ne 401) { throw 'Guest accessed mail' }
    $csrf=Invoke-WebRequest -Uri ($base+'mail-api.php?action=send') -Method Post -WebSession $a.session -Headers @{'X-AOV-CSRF'='invalid'} -ContentType 'application/json' -Body '{}' -UseBasicParsing -SkipHttpErrorCheck
    if ($csrf.StatusCode -ne 403) { throw 'Invalid CSRF accepted' }
    $needle='mail-smoke-'+(Get-Random)
    $send=Invoke-RestMethod -Uri ($base+'mail-api.php?action=send') -Method Post -WebSession $a.session -Headers @{'X-AOV-CSRF'=$a.csrf} -ContentType 'application/json' -Body (@{to=$b.name;subject='Phase 2 test';body=$needle;replyTo=$null}|ConvertTo-Json -Compress)
    if ($send.message -ne 'Message sent') { throw 'Send failed' }
    $inbox=Invoke-RestMethod -Uri ($base+'mail-api.php?action=list&folder=inbox&page=0') -WebSession $b.session
    if ($inbox.unread -ne 1 -or $inbox.messages[0].other_name -ne $a.name) { throw 'Recipient inbox/unread failed' }
    $id=[int]$inbox.messages[0].id
    $leak=Invoke-WebRequest -Uri ($base+'mail-api.php?action=read') -Method Post -WebSession $c.session -Headers @{'X-AOV-CSRF'=$c.csrf} -ContentType 'application/json' -Body (@{id=$id}|ConvertTo-Json) -UseBasicParsing -SkipHttpErrorCheck
    if ($leak.StatusCode -ne 404) { throw 'Third party read a message' }
    $read=Invoke-RestMethod -Uri ($base+'mail-api.php?action=read') -Method Post -WebSession $b.session -Headers @{'X-AOV-CSRF'=$b.csrf} -ContentType 'application/json' -Body (@{id=$id}|ConvertTo-Json)
    if ($read.message.body -ne $needle -or $read.unread -ne 0) { throw 'Read/unread failed' }
    $reply=Invoke-RestMethod -Uri ($base+'mail-api.php?action=send') -Method Post -WebSession $b.session -Headers @{'X-AOV-CSRF'=$b.csrf} -ContentType 'application/json' -Body (@{to=$a.name;subject='Re: Phase 2 test';body='Reply';replyTo=$id}|ConvertTo-Json -Compress)
    if ($reply.message -ne 'Message sent') { throw 'Reply failed' }
    $badReply=Invoke-WebRequest -Uri ($base+'mail-api.php?action=send') -Method Post -WebSession $c.session -Headers @{'X-AOV-CSRF'=$c.csrf} -ContentType 'application/json' -Body (@{to=$a.name;subject='Forgery';body='No';replyTo=$id}|ConvertTo-Json -Compress) -UseBasicParsing -SkipHttpErrorCheck
    if ($badReply.StatusCode -ne 403) { throw 'Unrelated reply accepted' }
    $report=Invoke-RestMethod -Uri ($base+'mail-api.php?action=report') -Method Post -WebSession $b.session -Headers @{'X-AOV-CSRF'=$b.csrf} -ContentType 'application/json' -Body (@{id=$id;reason='Spam'}|ConvertTo-Json -Compress)
    if ($report.message -notmatch 'Report sent') { throw 'Mail report failed' }
    $notAdmin=Invoke-WebRequest -Uri ($base+'admin-api.php') -WebSession $b.session -UseBasicParsing -SkipHttpErrorCheck
    if ($notAdmin.StatusCode -ne 403) { throw 'Member accessed reports' }
    $admin=Invoke-RestMethod -Uri ($base+'admin-api.php') -WebSession $c.session
    $reportRow=@($admin.mailReports | Where-Object { $_.sender -eq $a.name -and $_.reporter -eq $b.name })[0]
    if (!$reportRow -or $reportRow.body -ne $needle) { throw 'Administrator report review failed' }
    $closed=Invoke-RestMethod -Uri ($base+'admin-api.php?action=mail_report_close') -Method Post -WebSession $c.session -Headers @{'X-AOV-CSRF'=$c.csrf} -ContentType 'application/json' -Body (@{id=[int]$reportRow.id}|ConvertTo-Json -Compress)
    if (@($closed.mailReports | Where-Object { [int]$_.id -eq [int]$reportRow.id }).Count) { throw 'Report close failed' }
    $env:AOV_MAIL_BLOCK_USER=[string]$b.id; $env:AOV_MAIL_BLOCKED=[string]$a.id
    & $php -r 'require "C:/xampp/htdocs/aov/bootstrap.php"; aov_db()->prepare("INSERT INTO aov_chat_blocks(user_id,blocked_id,created_at) VALUES(?,?,?)")->execute([(int)getenv("AOV_MAIL_BLOCK_USER"),(int)getenv("AOV_MAIL_BLOCKED"),time()]);'
    $blocked=Invoke-WebRequest -Uri ($base+'mail-api.php?action=send') -Method Post -WebSession $a.session -Headers @{'X-AOV-CSRF'=$a.csrf} -ContentType 'application/json' -Body (@{to=$b.name;subject='Blocked';body='No delivery';replyTo=$null}|ConvertTo-Json -Compress) -UseBasicParsing -SkipHttpErrorCheck
    if ($blocked.StatusCode -ne 400) { throw 'Blocked sender could send mail' }
    $strangerArchive=Invoke-WebRequest -Uri ($base+'mail-api.php?action=archive') -Method Post -WebSession $c.session -Headers @{'X-AOV-CSRF'=$c.csrf} -ContentType 'application/json' -Body (@{id=$id}|ConvertTo-Json) -UseBasicParsing -SkipHttpErrorCheck
    if ($strangerArchive.StatusCode -ne 404) { throw 'Third party archived a message' }
    foreach ($n in 1..20) {
        $null=Invoke-RestMethod -Uri ($base+'mail-api.php?action=send') -Method Post -WebSession $c.session -Headers @{'X-AOV-CSRF'=$c.csrf} -ContentType 'application/json' -Body (@{to=$a.name;subject="Limit $n";body='Test';replyTo=$null}|ConvertTo-Json -Compress)
    }
    $overLimit=Invoke-WebRequest -Uri ($base+'mail-api.php?action=send') -Method Post -WebSession $c.session -Headers @{'X-AOV-CSRF'=$c.csrf} -ContentType 'application/json' -Body (@{to=$a.name;subject='Too many';body='Test';replyTo=$null}|ConvertTo-Json -Compress) -UseBasicParsing -SkipHttpErrorCheck
    if ($overLimit.StatusCode -ne 429) { throw 'Hourly send limit not enforced' }
    $archive=Invoke-RestMethod -Uri ($base+'mail-api.php?action=archive') -Method Post -WebSession $b.session -Headers @{'X-AOV-CSRF'=$b.csrf} -ContentType 'application/json' -Body (@{id=$id}|ConvertTo-Json)
    if ($archive.message -ne 'Message archived') { throw 'Archive failed' }
    $after=Invoke-RestMethod -Uri ($base+'mail-api.php?action=list&folder=inbox&page=0') -WebSession $b.session
    if (@($after.messages | Where-Object { [int]$_.id -eq $id }).Count) { throw 'Archived message remained visible' }
    $sent=Invoke-RestMethod -Uri ($base+'mail-api.php?action=list&folder=sent&page=0') -WebSession $a.session
    if (!(@($sent.messages | Where-Object { [int]$_.id -eq $id }).Count)) { throw 'Recipient archive removed sender copy' }
    $directory=Invoke-RestMethod -Uri ($base+"mail-api.php?action=directory&q=$($b.name.Substring(0,8))") -WebSession $a.session
    if ($b.name -notin @($directory.members)) { throw 'Member directory failed' }
    Write-Output 'PASS: guest/CSRF gates, send, inbox, unread/read, third-party denial, reply ownership, block, report/admin close, archive isolation, sent, directory, hourly limit'
} finally {
    $env:AOV_MAIL_IDS = (($users | ForEach-Object { $_.id }) -join ',')
    & $php -r 'require "C:/xampp/htdocs/aov/bootstrap.php"; $d=aov_db(); foreach(explode(",",getenv("AOV_MAIL_IDS")) as $value) { if(!ctype_digit($value)) continue; $id=(int)$value; $q=$d->prepare("SELECT username FROM users WHERE id=?"); $q->execute([$id]); $name=(string)$q->fetchColumn(); if(str_starts_with($name,"AOVMail")) { $d->prepare("DELETE FROM aov_security_events WHERE user_id=?")->execute([$id]); $d->prepare("DELETE FROM users WHERE id=?")->execute([$id]); } }'
    Remove-Item Env:AOV_PRIVATE_DIR,Env:AOV_MAIL_IDS,Env:AOV_MAIL_NAME,Env:AOV_MAIL_PASSWORD,Env:AOV_MAIL_IP,Env:AOV_MAIL_ADMIN_ID,Env:AOV_MAIL_BLOCK_USER,Env:AOV_MAIL_BLOCKED -ErrorAction SilentlyContinue
}
