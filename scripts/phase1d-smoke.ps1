$ErrorActionPreference = 'Stop'
$base = 'http://mother.local:8080/aov/'
$php = 'C:\xampp\php\php.exe'
$private = 'C:\xampp\aov-private'
$users = @(
    @{ name = 'AOVSmoke' + (Get-Random -Minimum 100000 -Maximum 999999); password = [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(24)); ip = '198.51.100.' + (Get-Random -Minimum 10 -Maximum 120) },
    @{ name = 'AOVSmoke' + (Get-Random -Minimum 100000 -Maximum 999999); password = [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(24)); ip = '198.51.100.' + (Get-Random -Minimum 121 -Maximum 180) },
    @{ name = 'AOVSmoke' + (Get-Random -Minimum 100000 -Maximum 999999); password = [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(24)); ip = '198.51.100.' + (Get-Random -Minimum 181 -Maximum 240) }
)
$ids = @()
$filterTerm = ''
$env:AOV_PRIVATE_DIR = $private
try {
    foreach ($user in $users) {
        $env:AOV_SMOKE_NAME = $user.name
        $env:AOV_SMOKE_PASSWORD = $user.password
        $env:AOV_SMOKE_IP = $user.ip
        $id = & $php -r '$_SERVER["REMOTE_ADDR"]=getenv("AOV_SMOKE_IP"); require "C:/xampp/htdocs/aov/bootstrap.php"; $a=new Delight\Auth\Auth(aov_db()); $id=$a->registerWithUniqueUsername(strtolower(getenv("AOV_SMOKE_NAME"))."@example.com",getenv("AOV_SMOKE_PASSWORD"),getenv("AOV_SMOKE_NAME")); aov_db()->prepare("INSERT INTO aov_profiles (user_id,terms_accepted_at) VALUES (?,?)")->execute([$id,time()]); echo $id;'
        if ($LASTEXITCODE -ne 0 -or !($id -match '^\d+$')) { throw 'Could not create a test account' }
        $user.id = [int]$id
        $ids += [int]$id
        if ($ids.Count -eq 1) {
            $env:AOV_SMOKE_ID = [string]$id
            & $php -r 'require "C:/xampp/htdocs/aov/bootstrap.php"; $admin=new Delight\Auth\Administration(aov_db()); $admin->addRoleForUserById((int)getenv("AOV_SMOKE_ID"),Delight\Auth\Role::ADMIN);'
            if ($LASTEXITCODE -ne 0) { throw 'Could not assign test admin role' }
        }
        $user.session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        $page = Invoke-WebRequest -Uri $base -WebSession $user.session -UseBasicParsing
        $state = ([regex]::Match($page.Content, '<script type="application/json" id="aov-state">([^<]+)</script>').Groups[1].Value | ConvertFrom-Json)
        $body = @{ name = $user.name; password = $user.password } | ConvertTo-Json -Compress
        $login = Invoke-RestMethod -Uri ($base + 'api.php?action=login') -Method Post -WebSession $user.session -Headers @{ 'X-AOV-CSRF' = $state.csrf } -ContentType 'application/json' -Body $body
        if (!$login.loggedIn) { throw 'AOV login failed' }
        $user.csrf = $login.csrf
    }
    $a = $users[0]; $b = $users[1]; $stranger = $users[2]
    $stateA = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=state') -WebSession $a.session
    if ($stateA.userId -ne $a.id) { throw 'Chat identity mismatch' }
    if (!(@($stateA.rooms | Where-Object { $_.name -eq 'Retro Computing' -and $_.category -eq 'Computing' }).Count)) { throw 'Interest-based room directory is missing' }
    $engineUrl = $base + 'chat-engine/?ajax=1&channelID=0&lastID=0'
    $send = Invoke-WebRequest -Uri $engineUrl -Method Post -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = $a.csrf } -ContentType 'application/x-www-form-urlencoded' -Body @{ text = 'Phase 1D smoke hello' } -UseBasicParsing
    if ($send.StatusCode -ne 200) { throw 'Chat send failed' }
    $received = Invoke-WebRequest -Uri $engineUrl -WebSession $b.session -Headers @{ 'X-AOV-CSRF' = $b.csrf } -UseBasicParsing
    if (!$received.Content.Contains('Phase 1D smoke hello')) { throw 'Second account did not receive public message' }
    [xml]$receivedXml = $received.Content
    $reportedMessage = @($receivedXml.root.messages.message | Where-Object { $_.text.'#cdata-section' -eq 'Phase 1D smoke hello' })[0]
    if (!$reportedMessage) { throw 'Could not find message for report test' }
    $report = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=report') -Method Post -WebSession $b.session -Headers @{ 'X-AOV-CSRF' = $b.csrf } -ContentType 'application/json' -Body (@{ messageId = [int]$reportedMessage.id } | ConvertTo-Json)
    if (!$report.ok) { throw 'Abuse report failed' }
    $buddy = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=buddy_add') -Method Post -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = $a.csrf } -ContentType 'application/json' -Body (@{ name = $b.name } | ConvertTo-Json)
    if (!$buddy.ok) { throw 'Buddy add failed' }
    $stateA = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=state') -WebSession $a.session
    if (!(($stateA.buddies | Where-Object { $_.id -eq $b.id }).Count)) { throw 'Saved buddy is missing' }
    $group = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=buddy_group_set') -Method Post -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = $a.csrf } -ContentType 'application/json' -Body (@{ name = $b.name; group = 'Retro Friends' } | ConvertTo-Json -Compress)
    if (!$group.ok) { throw 'Buddy group update failed' }
    $away = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=away_set') -Method Post -WebSession $b.session -Headers @{ 'X-AOV-CSRF' = $b.csrf } -ContentType 'application/json' -Body (@{ away = $true; message = 'Be right back' } | ConvertTo-Json -Compress)
    if (!$away.ok) { throw 'Away status update failed' }
    $stateA = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=state') -WebSession $a.session
    $groupBuddy = @($stateA.buddies | Where-Object { $_.id -eq $b.id })[0]
    if ($groupBuddy.group_name -ne 'Retro Friends' -or [int]$groupBuddy.is_away -ne 1 -or $groupBuddy.away_message -ne 'Be right back') { throw 'Buddy group or away status was not saved' }
    $directText = 'private-smoke-' + (Get-Random)
    $null = Invoke-WebRequest -Uri $engineUrl -Method Post -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = $a.csrf } -ContentType 'application/x-www-form-urlencoded' -Body @{ text = "/msg $($b.name) $directText" } -UseBasicParsing
    $direct = Invoke-WebRequest -Uri $engineUrl -WebSession $b.session -Headers @{ 'X-AOV-CSRF' = $b.csrf } -UseBasicParsing
    if (!$direct.Content.Contains($directText)) { throw 'Direct message was not delivered' }
    [xml]$directXml = $direct.Content
    $directRow = @($directXml.root.messages.message | Where-Object { $_.text.'#cdata-section' -like "*$directText*" })[0]
    if (!$directRow) { throw 'Direct message ID was not returned' }
    $privateReport = Invoke-WebRequest -Uri ($base + 'chat-api.php?action=report') -Method Post -WebSession $stranger.session -Headers @{ 'X-AOV-CSRF' = $stranger.csrf } -ContentType 'application/json' -Body (@{ messageId = [int]$directRow.id } | ConvertTo-Json) -UseBasicParsing -SkipHttpErrorCheck
    if ($privateReport.StatusCode -ne 404) { throw 'Unrelated member could report a private message' }
    $privateId = 500000000 + $a.id
    $privateUrl = $base + "chat-engine/?ajax=1&channelID=$privateId&lastID=0"
    $privateJoin = Invoke-WebRequest -Uri $privateUrl -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = $a.csrf } -UseBasicParsing
    if (!$privateJoin.Content.Contains("type=`"channelID`"><![CDATA[$privateId]]>")) { throw 'Owner could not enter private room' }
    $uninvited = Invoke-WebRequest -Uri $privateUrl -WebSession $stranger.session -Headers @{ 'X-AOV-CSRF' = $stranger.csrf } -UseBasicParsing
    if ($uninvited.Content.Contains("type=`"channelID`"><![CDATA[$privateId]]>")) { throw 'Uninvited account entered a private room' }
    $null = Invoke-WebRequest -Uri $privateUrl -Method Post -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = $a.csrf } -ContentType 'application/x-www-form-urlencoded' -Body @{ text = "/invite $($b.name)" } -UseBasicParsing
    $stateB = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=state') -WebSession $b.session
    if (!(($stateB.rooms | Where-Object { $_.id -eq $privateId }).Count)) { throw 'Private room invitation missing' }
    $privateGuest = Invoke-WebRequest -Uri $privateUrl -WebSession $b.session -Headers @{ 'X-AOV-CSRF' = $b.csrf } -UseBasicParsing
    if (!$privateGuest.Content.Contains("type=`"channelID`"><![CDATA[$privateId]]>")) { throw 'Invited guest could not enter private room' }
    $repeatText = 'repeat-smoke-' + (Get-Random)
    $null = Invoke-WebRequest -Uri $engineUrl -Method Post -WebSession $b.session -Headers @{ 'X-AOV-CSRF' = $b.csrf } -ContentType 'application/x-www-form-urlencoded' -Body @{ text = $repeatText } -UseBasicParsing
    $repeat = Invoke-WebRequest -Uri $engineUrl -Method Post -WebSession $b.session -Headers @{ 'X-AOV-CSRF' = $b.csrf } -ContentType 'application/x-www-form-urlencoded' -Body @{ text = $repeatText } -UseBasicParsing
    if (!$repeat.Headers['X-AOV-Chat-Notice']) { throw 'Repeated chat message was not blocked' }
    $filterTerm = 'filtersmoke' + (Get-Random -Minimum 100000 -Maximum 999999)
    $forbiddenFilter = Invoke-WebRequest -Uri ($base + 'chat-api.php?action=admin_filter_terms') -Method Post -WebSession $b.session -Headers @{ 'X-AOV-CSRF' = $b.csrf } -ContentType 'application/json' -Body '{}' -UseBasicParsing -SkipHttpErrorCheck
    if ($forbiddenFilter.StatusCode -ne 403) { throw 'Non-admin accessed chat filters' }
    $added = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=admin_filter_add') -Method Post -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = $a.csrf } -ContentType 'application/json' -Body (@{ term = $filterTerm } | ConvertTo-Json)
    $entry = @($added.terms | Where-Object { $_.term -eq $filterTerm })[0]
    if (!$entry) { throw 'Blocked phrase was not saved' }
    $filtered = Invoke-WebRequest -Uri $engineUrl -Method Post -WebSession $stranger.session -Headers @{ 'X-AOV-CSRF' = $stranger.csrf } -ContentType 'application/x-www-form-urlencoded' -Body @{ text = "hello $filterTerm everyone" } -UseBasicParsing
    if (!$filtered.Headers['X-AOV-Chat-Notice']) { throw 'Blocked phrase passed the chat filter' }
    $null = Invoke-WebRequest -Uri $engineUrl -Method Post -WebSession $stranger.session -Headers @{ 'X-AOV-CSRF' = $stranger.csrf } -ContentType 'application/x-www-form-urlencoded' -Body @{ text = "hello $filterTerm again" } -UseBasicParsing
    $muted = Invoke-WebRequest -Uri $engineUrl -Method Post -WebSession $stranger.session -Headers @{ 'X-AOV-CSRF' = $stranger.csrf } -ContentType 'application/x-www-form-urlencoded' -Body @{ text = "hello $filterTerm third" } -UseBasicParsing
    if (!(($muted.Headers['X-AOV-Chat-Notice'] -join ' ') -match 'muted')) { throw 'Repeated blocked messages did not trigger automatic mute' }
    $guard = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=admin_filter_terms') -Method Post -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = $a.csrf } -ContentType 'application/json' -Body '{}'
    if (!(@($guard.events | Where-Object { $_.username -eq $stranger.name -and $_.event -eq 'chat_auto_mute' }).Count)) { throw 'Automatic mute was not logged' }
    $null = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=admin_guard_unmute') -Method Post -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = $a.csrf } -ContentType 'application/json' -Body (@{ name = $stranger.name } | ConvertTo-Json)
    $clean = Invoke-WebRequest -Uri $engineUrl -Method Post -WebSession $stranger.session -Headers @{ 'X-AOV-CSRF' = $stranger.csrf } -ContentType 'application/x-www-form-urlencoded' -Body @{ text = 'clean-smoke-' + (Get-Random) } -UseBasicParsing
    if ($clean.Headers['X-AOV-Chat-Notice']) { throw 'Administrator unmute did not restore chat access' }
    $burst = Invoke-WebRequest -Uri $engineUrl -Method Post -WebSession $stranger.session -Headers @{ 'X-AOV-CSRF' = $stranger.csrf } -ContentType 'application/x-www-form-urlencoded' -Body @{ text = 'burst-smoke-' + (Get-Random) } -UseBasicParsing
    if (!$burst.Headers['X-AOV-Chat-Notice']) { throw 'Rapid-posting burst was not blocked' }
    $removed = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=admin_filter_remove') -Method Post -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = $a.csrf } -ContentType 'application/json' -Body (@{ id = [int]$entry.id } | ConvertTo-Json)
    if (@($removed.terms | Where-Object { $_.term -eq $filterTerm }).Count) { throw 'Blocked phrase could not be removed' }
    $null = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=block') -Method Post -WebSession $b.session -Headers @{ 'X-AOV-CSRF' = $b.csrf } -ContentType 'application/json' -Body (@{ name = $a.name } | ConvertTo-Json)
    $blockedText = 'blocked-smoke-' + (Get-Random)
    $null = Invoke-WebRequest -Uri $engineUrl -Method Post -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = $a.csrf } -ContentType 'application/x-www-form-urlencoded' -Body @{ text = "/msg $($b.name) $blockedText" } -UseBasicParsing
    $blocked = Invoke-WebRequest -Uri $engineUrl -WebSession $b.session -Headers @{ 'X-AOV-CSRF' = $b.csrf } -UseBasicParsing
    if ($blocked.Content.Contains($blockedText)) { throw 'Blocked direct message was delivered' }
    $freshSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $freshPage = Invoke-WebRequest -Uri $base -WebSession $freshSession -UseBasicParsing
    $freshState = ([regex]::Match($freshPage.Content, '<script type="application/json" id="aov-state">([^<]+)</script>').Groups[1].Value | ConvertFrom-Json)
    $freshLogin = Invoke-RestMethod -Uri ($base + 'api.php?action=login') -Method Post -WebSession $freshSession -Headers @{ 'X-AOV-CSRF' = $freshState.csrf } -ContentType 'application/json' -Body (@{ name = $b.name; password = $b.password } | ConvertTo-Json -Compress)
    if (!$freshLogin.loggedIn) { throw 'Fresh-session login failed' }
    $freshState = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=state') -WebSession $freshSession
    if ($a.id -notin @($freshState.blockedIds)) { throw 'Block did not persist across sign-in' }
    $publicBlockedText = 'hidden-public-' + (Get-Random)
    $null = Invoke-WebRequest -Uri $engineUrl -Method Post -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = $a.csrf } -ContentType 'application/x-www-form-urlencoded' -Body @{ text = $publicBlockedText } -UseBasicParsing
    $blockedRoom = Invoke-WebRequest -Uri $engineUrl -WebSession $freshSession -Headers @{ 'X-AOV-CSRF' = $freshLogin.csrf } -UseBasicParsing
    if ($blockedRoom.Content.Contains($publicBlockedText)) { throw 'Blocked room message reached the server response' }
    $blockedMail = Invoke-WebRequest -Uri ($base + 'mail-api.php?action=send') -Method Post -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = $a.csrf } -ContentType 'application/json' -Body (@{ to = $b.name; subject = 'Blocked test'; body = 'Must not arrive'; replyTo = $null } | ConvertTo-Json -Compress) -UseBasicParsing -SkipHttpErrorCheck
    if ($blockedMail.StatusCode -ne 400) { throw 'Blocked member could send internal mail' }
    $forbidden = Invoke-WebRequest -Uri ($base + 'chat-api.php?action=admin_reports') -Method Post -WebSession $b.session -Headers @{ 'X-AOV-CSRF' = $b.csrf } -ContentType 'application/json' -Body '{}' -UseBasicParsing -SkipHttpErrorCheck
    if ($forbidden.StatusCode -ne 403) { throw 'Non-admin accessed moderation reports' }
    $reports = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=admin_reports') -Method Post -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = $a.csrf } -ContentType 'application/json' -Body '{}'
    if (!(($reports.reports | Where-Object { $_.target -eq $a.name }).Count)) { throw 'Admin could not review report' }
    $ban = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=admin_ban') -Method Post -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = $a.csrf } -ContentType 'application/json' -Body (@{ name = $b.name; minutes = 5 } | ConvertTo-Json)
    if (!$ban.ok) { throw 'Admin ban failed' }
    $unban = Invoke-RestMethod -Uri ($base + 'chat-api.php?action=admin_unban') -Method Post -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = $a.csrf } -ContentType 'application/json' -Body (@{ name = $b.name } | ConvertTo-Json)
    if (!$unban.ok) { throw 'Admin unban failed' }
    $csrfDenied = Invoke-WebRequest -Uri $engineUrl -WebSession $a.session -Headers @{ 'X-AOV-CSRF' = 'invalid' } -UseBasicParsing -SkipHttpErrorCheck
    if ($csrfDenied.StatusCode -ne 403) { throw 'Engine accepted request without CSRF token' }
    $anonymous = Invoke-WebRequest -Uri ($base + 'chat-api.php?action=state') -UseBasicParsing -SkipHttpErrorCheck
    if ($anonymous.StatusCode -ne 401) { throw 'Chat API accepted anonymous request' }
    Write-Output 'PASS: unified identity, public/private chat, private report denial, block persistence across sign-in, server-side room filtering and mail denial, room permissions, flood filters, moderation, CSRF and anonymous access checks'
} finally {
    if ($filterTerm -match '^filtersmoke\d+$') {
        $env:AOV_SMOKE_TERM = $filterTerm
        & $php -r 'require "C:/xampp/htdocs/aov/bootstrap.php"; aov_db()->prepare("DELETE FROM aov_chat_filter_terms WHERE term=?")->execute([getenv("AOV_SMOKE_TERM")]);'
    }
    foreach ($id in $ids) {
        $env:AOV_SMOKE_ID = [string]$id
        & $php -r '$id=(int)getenv("AOV_SMOKE_ID"); require "C:/xampp/htdocs/aov/bootstrap.php"; $d=aov_db(); $q=$d->prepare("SELECT username FROM users WHERE id=?"); $q->execute([$id]); $name=(string)$q->fetchColumn(); if(str_starts_with($name,"AOVSmoke")) {$d->prepare("DELETE FROM aov_security_events WHERE user_id=?")->execute([$id]); $d->prepare("DELETE FROM users WHERE id=?")->execute([$id]); $c=aov_config()["chat_mysql"]; $m=new PDO("mysql:host=".$c["host"].";dbname=".$c["name"],$c["user"],$c["password"]); foreach(["ajax_chat_messages","ajax_chat_online","ajax_chat_bans","ajax_chat_invitations"] as $table) {$m->prepare("DELETE FROM ".$table." WHERE userID=?")->execute([$id]);} $m->prepare("DELETE FROM ajax_chat_messages WHERE userID=2147483647 AND text LIKE ?")->execute(["%".$name."%"]); }'
    }
    Remove-Item Env:AOV_SMOKE_ID,Env:AOV_SMOKE_NAME,Env:AOV_SMOKE_PASSWORD,Env:AOV_SMOKE_IP,Env:AOV_SMOKE_TERM,Env:AOV_PRIVATE_DIR -ErrorAction SilentlyContinue
}
