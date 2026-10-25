$ErrorActionPreference = 'Stop'
$base = 'http://mother.local:8080/aov/'
$php = 'C:\xampp\php\php.exe'
$env:AOV_PRIVATE_DIR = 'C:\xampp\aov-private'
$prefix = 'AOVAudit' + (Get-Random -Minimum 100000 -Maximum 999999)
$password = [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(24))
$ids = @()
$roomId = 0
try {
    foreach ($suffix in @('Admin','Member')) {
        $env:AOV_AUDIT_NAME = $prefix + $suffix
        $env:AOV_AUDIT_PASS = $password
        $env:AOV_AUDIT_IP = '2001:db8::' + (Get-Random -Minimum 100000 -Maximum 999999)
        $id = & $php -r '$_SERVER["REMOTE_ADDR"]=getenv("AOV_AUDIT_IP"); require "C:/xampp/htdocs/aov/bootstrap.php"; $a=new Delight\Auth\Auth(aov_db()); $id=$a->registerWithUniqueUsername(strtolower(getenv("AOV_AUDIT_NAME"))."@example.com",getenv("AOV_AUDIT_PASS"),getenv("AOV_AUDIT_NAME")); aov_db()->prepare("INSERT INTO aov_profiles (user_id,terms_accepted_at) VALUES (?,?)")->execute([$id,time()]); echo $id;'
        if ($LASTEXITCODE -ne 0 -or !($id -match '^\d+$')) { throw 'Could not create test user' }
        $ids += [int]$id
    }
    $env:AOV_AUDIT_ID = [string]$ids[0]
    & $php -r 'require "C:/xampp/htdocs/aov/bootstrap.php"; (new Delight\Auth\Administration(aov_db()))->addRoleForUserById((int)getenv("AOV_AUDIT_ID"),Delight\Auth\Role::ADMIN);'
    if ($LASTEXITCODE -ne 0) { throw 'Could not assign test admin role' }
    $sessions = @()
    for ($i=0; $i -lt 2; $i++) {
        $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        $page = Invoke-WebRequest -Uri $base -WebSession $session -UseBasicParsing
        $state = ([regex]::Match($page.Content, '<script type="application/json" id="aov-state">([^<]+)</script>').Groups[1].Value | ConvertFrom-Json)
        $login = Invoke-RestMethod -Uri ($base+'api.php?action=login') -Method Post -WebSession $session -Headers @{'X-AOV-CSRF'=$state.csrf} -ContentType 'application/json' -Body (@{name=$prefix+@('Admin','Member')[$i];password=$password}|ConvertTo-Json -Compress)
        $sessions += @{session=$session;csrf=$login.csrf}
    }
    $admin = $sessions[0]; $member = $sessions[1]
    $denied = Invoke-WebRequest -Uri ($base+'admin-api.php') -WebSession $member.session -UseBasicParsing -SkipHttpErrorCheck
    if ($denied.StatusCode -ne 403) { throw 'Member reached administrator API' }
    $panel = Invoke-WebRequest -Uri ($base+'admin.php') -WebSession $admin.session -UseBasicParsing
    if ($panel.StatusCode -ne 200) { throw 'Administrator panel unavailable' }
    $state = Invoke-RestMethod -Uri ($base+'admin-api.php') -WebSession $admin.session
    if (!(@($state.users | Where-Object { [int]$_.id -eq $ids[1] }).Count)) { throw 'Member missing from administrator list' }
    $headers = @{'X-AOV-CSRF'=$admin.csrf}
    $badCsrf = Invoke-WebRequest -Uri ($base+'admin-api.php?action=room_add') -Method Post -WebSession $admin.session -Headers @{'X-AOV-CSRF'='invalid'} -ContentType 'application/json' -Body '{}' -UseBasicParsing -SkipHttpErrorCheck
    if ($badCsrf.StatusCode -ne 403) { throw 'Administrator mutation accepted invalid CSRF' }
    $badChannel = Invoke-WebRequest -Uri ($base+'admin-api.php?action=channel_set') -Method Post -WebSession $admin.session -Headers $headers -ContentType 'application/json' -Body (@{id='sports';url='javascript:alert(1)'}|ConvertTo-Json) -UseBasicParsing -SkipHttpErrorCheck
    if ($badChannel.StatusCode -ne 400) { throw 'Unsafe channel URL was accepted' }
    $roomName = $prefix + 'Room'
    $state = Invoke-RestMethod -Uri ($base+'admin-api.php?action=room_add') -Method Post -WebSession $admin.session -Headers $headers -ContentType 'application/json' -Body (@{name=$roomName;category='Nostalgia'}|ConvertTo-Json)
    $room = @($state.rooms | Where-Object { $_.name -eq $roomName })[0]
    if (!$room -or $room.category -ne 'Nostalgia') { throw 'Room category was not saved' }
    $roomId = [int]$room.id
    $joined = Invoke-WebRequest -Uri ($base+"chat-engine/?ajax=1&channelID=$roomId&lastID=0") -WebSession $member.session -Headers @{'X-AOV-CSRF'=$member.csrf} -UseBasicParsing
    if (!$joined.Content.Contains("type=`"channelID`"><![CDATA[$roomId]]>")) { throw 'Member could not enter the new room' }
    $state = Invoke-RestMethod -Uri ($base+'admin-api.php?action=room_toggle') -Method Post -WebSession $admin.session -Headers $headers -ContentType 'application/json' -Body (@{id=$roomId}|ConvertTo-Json)
    if ((@($state.rooms | Where-Object { [int]$_.id -eq $roomId })[0]).enabled -ne 0) { throw 'Room disable failed' }
    $removedRoom = Invoke-RestMethod -Uri ($base+'chat-api.php?action=state') -WebSession $member.session
    if (@($removedRoom.rooms | Where-Object { [int]$_.id -eq $roomId }).Count) { throw 'Disabled room remained in member navigation' }
    $state = Invoke-RestMethod -Uri ($base+'admin-api.php?action=rename') -Method Post -WebSession $admin.session -Headers $headers -ContentType 'application/json' -Body (@{id=$ids[1];name=$prefix+'Renamed'}|ConvertTo-Json)
    if (!(@($state.users | Where-Object { [int]$_.id -eq $ids[1] -and $_.username -eq $prefix+'Renamed' }).Count)) { throw 'Rename failed' }
    $state = Invoke-RestMethod -Uri ($base+'admin-api.php?action=suspend') -Method Post -WebSession $admin.session -Headers $headers -ContentType 'application/json' -Body (@{id=$ids[1]}|ConvertTo-Json)
    $revoked = Invoke-WebRequest -Uri ($base+'chat-api.php?action=state') -WebSession $member.session -UseBasicParsing -SkipHttpErrorCheck
    if ($revoked.StatusCode -ne 401) { throw 'Suspended member session remained active' }
    $state = Invoke-RestMethod -Uri ($base+'admin-api.php?action=restore') -Method Post -WebSession $admin.session -Headers $headers -ContentType 'application/json' -Body (@{id=$ids[1]}|ConvertTo-Json)
    if ((@($state.users | Where-Object { [int]$_.id -eq $ids[1] })[0]).status -ne 0) { throw 'Restore failed' }
    Write-Output 'PASS: admin role and CSRF gates, unsafe URL rejection, member listing, room add/join/disable, rename, suspend/session revocation, restore'
} finally {
    $env:AOV_AUDIT_IDS = ($ids -join ',')
    $env:AOV_AUDIT_ROOM = [string]$roomId
    & $php -r 'require "C:/xampp/htdocs/aov/bootstrap.php"; $d=aov_db(); $c=aov_config()["chat_mysql"]; $m=new PDO("mysql:host=".$c["host"].";dbname=".$c["name"],$c["user"],$c["password"]); foreach(explode(",",getenv("AOV_AUDIT_IDS")) as $id) { if(ctype_digit($id)) { $q=$d->prepare("SELECT username FROM users WHERE id=?"); $q->execute([(int)$id]); $name=(string)$q->fetchColumn(); if(str_starts_with($name,"AOVAudit")) { $d->prepare("DELETE FROM aov_security_events WHERE user_id=?")->execute([(int)$id]); $d->prepare("DELETE FROM users WHERE id=?")->execute([(int)$id]); foreach(["ajax_chat_messages","ajax_chat_online","ajax_chat_bans","ajax_chat_invitations"] as $table) $m->prepare("DELETE FROM ".$table." WHERE userID=?")->execute([(int)$id]); $m->prepare("DELETE FROM ajax_chat_messages WHERE userID=2147483647 AND text LIKE ?")->execute(["%".$name."%"]); } } } $room=(int)getenv("AOV_AUDIT_ROOM"); if($room>2) $d->prepare("DELETE FROM aov_chat_rooms WHERE id=? AND name LIKE ?")->execute([$room,"AOVAudit%"]);'
    Remove-Item Env:AOV_PRIVATE_DIR,Env:AOV_AUDIT_NAME,Env:AOV_AUDIT_PASS,Env:AOV_AUDIT_IP,Env:AOV_AUDIT_ID,Env:AOV_AUDIT_IDS,Env:AOV_AUDIT_ROOM -ErrorAction SilentlyContinue
}
