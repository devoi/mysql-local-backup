<?php

date_default_timezone_set('Asia/Seoul');
// error_reporting(E_ALL);
// ini_set('display_errors', TRUE);
ini_set('memory_limit','-1');
// set_time_limit(0);

class MySQLDump
{
    public $slack_hook_url;
    public $database_ini;
    public $settings_ini;

    public $local_username;
    public $local_password;
    public $local_host;

    public $root_path = './';
    public $backup_time;

    function __construct()
    {
        $this->database_ini = 'database.ini';
        $this->settings_ini = 'settings.ini';

        if (file_exists($this->root_path . $this->settings_ini)) {
            $init = parse_ini_file($this->root_path . $this->settings_ini);
            $this->slack_hook_url = $init['slack_hook_url'];
            $this->local_username = $init['local_username'];
            $this->local_password = $init['local_password'];
            $this->local_host = $init['local_host'];
        } else {
            exit('settings.ini is not exists');
        }
    }

    function execute()
    {
        $this->slack("　\n ---------- 스크립트 시작 ---------- \n　");
        $this->backup_time = time(); // 백업 시작 시간

        $backup_result = $this->backup();
        $restore_result = $this->restore($backup_result);

        $time_diff = time() - $this->backup_time; // 백업 경과 시간

        // 백업 결과 슬랙 메시지로 보내기
        $this->slack(
            $this->to_slack_message(
                $backup_result, 
                $restore_result, 
                $time_diff
            )
        );
    }

    /**
     * 로컬에 덤프 파일 백업
     * 
     * @return array 덤프 백업 결과
     */
    function backup():array
    {
        $return = [
            'success' => [
                'filename' => []
            ], 
            'failed' => [
                'filename' => [], 
                'messages' => []
            ]
        ];

        if (file_exists($this->root_path . $this->database_ini)) {
            $db_info = parse_ini_file($this->root_path . $this->database_ini, true);

            foreach ($db_info as $title => $info) {
                $host = $info['host'];
                $username = $info['username'];
                $password = str_replace('^', '^^', $info['password']);
                $database = (array) $info['database'];
                $ignore_table = (array) $info['ignore-table'];
            
                // DB 단위로 백업
                foreach ($database as $idx => $db) {
                    // 예외 테이블 구문 생성
                    if (isset($ignore_table[$idx]) && !empty($ignore_table[$idx])) {
                        $ignore_table[$idx] = array_map('trim', explode(',', $ignore_table[$idx]));
                        $ignore_table[$idx] = "--ignore-table={$db}." . implode(" --ignore-table={$db}.", $ignore_table[$idx]);
                    } else {
                        $ignore_table[$idx] = '';
                    }

                    $sql_filename = "dump-{$title}-{$db}.sql";

                    // 커맨드 실행
                    $output = [];
                    $command = "mysqldump.exe -u {$username} -p{$password} -h {$host} {$ignore_table[$idx]} --set-gtid-purged=OFF --databases --add-drop-database --single-transaction {$db} -r {$this->root_path}{$sql_filename} 2>&1";
                    exec($command, $output, $result_code);

                    // 결과 기록
                    if ($result_code === 0) {
                        $return['success']['filename'][] = $sql_filename;
                    } else {
                        $return['failed']['filename'][] = $sql_filename;
                        $return['failed']['messages'][] = $this->get_error($output); // 실패 메시지
                    }
                }
            }
        }

        return $return;
    }

    /**
     * 로컬 DB에 덤프 복구 
     * 
     * @var array $backup_result 덤프 백업 결과
     * @return array 덤프 복구 결과
     */
    function restore(array $backup_result = []):array
    {
        $return = [
            'success' => [
                'filename' => []
            ], 
            'failed' => [
                'filename' => [],
                'messages' => []
            ]
        ];

        foreach ($backup_result as $result_key => $result) {
            foreach ($result['filename'] as $sql_filename) {
                switch ($result_key) {
                    case 'success': 
                        // 백업에 성공한 sql 파일만 복구 진행
                        // 커맨드 실행
                        $output = [];
                        $command = "mysql.exe -u {$this->local_username} -p{$this->local_password} -h {$this->local_host} < {$this->root_path}{$sql_filename} 2>&1";
                        exec($command, $output, $result_code);

                        // 결과 기록
                        if ($result_code === 0) {
                            $return['success']['filename'][] = $sql_filename;
                        } else {
                            $return['failed']['filename'][] = $sql_filename;
                            $return['failed']['messages'][] = $this->get_error($output); // 실패 메시지
                        }
                    case 'failed':
                        // SQL 파일 별도 관리
                        $this->file_storage($sql_filename);
                        break;
                }
            }
        }
        
        return $return;
    }

    /**
     * 생성 된 sql 파일 별도 저장 
     * 30일 지난 폴더는 삭제처리
     * 
     * @var string $sql_filename 파일명
     */
    function file_storage(string $sql_filename = '')
    {
        $storage_root = "{$this->root_path}/sql_storage/";
        $storage_today = $storage_root . date('Ymd') . '/';

        // sql 저장 폴더 생성
        if (!is_dir($storage_root)) mkdir($storage_root);
        if (!is_dir($storage_today)) mkdir($storage_today);

        // sql 파일 이동
        if (file_exists($this->root_path . $sql_filename)) {
            rename($this->root_path . $sql_filename, $storage_today . $sql_filename);
        }

        // 30일 지난 폴더 삭제
        foreach (array_diff(scandir($storage_root), array('.', '..')) as $folder_name) {
            $folder_path = $storage_root . $folder_name;
            
            $time_diff = time() - filemtime($folder_path);
            $date_diff = (int) ($time_diff / 60 / 60 / 24);

            // 30일 체크
            if ($date_diff >= 30) {
                // 폴더 내 파일 삭제
                array_map('unlink', glob("{$folder_path}/*.*"));
                rmdir($folder_path);
            }
        }
    }

    /**
     * Incoming WebHooks 앱 사용
     * 채널에 앱 추가 후 웹훅 URL 생성
     * 
     * @var string $message 슬랙으로 보낼 메시지
     */
    function slack(string $message = '')
    {
        $payload = ['text' => $message];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->slack_hook_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['payload' => json_encode($payload)]
        ]);
        curl_exec($curl);
        curl_close($curl);
    }

    /**
     * exec 함수 output 값 중, 에러 구문 확인 후 리턴
     * 
     * @var array $output
     * @return string
     */
    function get_error(array $output = []):string
    {
        $error_messages = array_filter($output, function($value, $key) {
            // mysqldump error
            if (is_numeric(strpos($value, 'Got error'))) {
                return $value;
            }

            // mysql error
            if (is_numeric(strpos($value, 'ERROR'))) {
                return $value;
            }

            // system error (stderr)
            if (is_numeric(strpos($value, 'System error'))) {
                return $value;
            }
        }, ARRAY_FILTER_USE_BOTH);

        return trim(implode("\n - ", $error_messages));
    }

    /**
     * 백업, 복구 결과 리턴
     * line break(\n) 사용 시, 쌍따옴표 필요
     * 
     * @var array $backup_result 백업 결과
     * @var array $restore_result 복구 결과
     * @var int $time_diff 경과 시간
     * @return string
     */
    function to_slack_message(array $backup_result = [], array $restore_result = [], int $time_diff = 0):string
    {
        $hours = (int) ($time_diff / 60 / 60);
        $minutes = $time_diff / 60 % 60;
        $seconds = $time_diff % 60;
        
        $backup_success_cnt = count($backup_result['success']['filename']);
        $backup_failed_cnt = count($backup_result['failed']['filename']);
        $restore_success_cnt = count($restore_result['success']['filename']);
        $restore_failed_cnt = count($restore_result['failed']['filename']);
        
        $backup_failed_file = ' - ' . implode("\n - ", $backup_result['failed']['filename']);
        $backup_failed_msg = ' - ' . implode("\n - ", $backup_result['failed']['messages']);
        $restore_failed_file = ' - ' . implode("\n - ", $restore_result['failed']['filename']);
        $restore_failed_msg = ' - ' . implode("\n - ", $restore_result['failed']['messages']);

        $slack_message = "📢\n경과 시간: {$hours}시간 {$minutes}분 {$seconds}초\n\n백업 성공: {$backup_success_cnt}, 백업 실패: {$backup_failed_cnt}\n복구 성공: {$restore_success_cnt}, 복구 실패: {$restore_failed_cnt}";
        if ($backup_failed_cnt > 0) {
            $slack_message .= "\n\n백업 실패 목록\n{$backup_failed_file}\n백업 실패 메시지\n{$backup_failed_msg}";
        }
        if ($restore_failed_cnt > 0) {
            $slack_message .= "\n\n복구 실패 목록\n{$restore_failed_file}\n복구 실패 메시지\n{$restore_failed_msg}";
        }
        
        return $slack_message;
    }

    /**
     * exec() $output 값에 표시되지 않는 시스템 에러 (stderr) 검출 용도
     * 에러 예시) The system cannot find the file specified.
     * 
     * @var string $command 실행 할 커맨드 명령어
     * @return array
     */
    function my_exec($command = ''):array
    {
        $return = [];

        $descriptor_spec = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"]  // stderr
        ];
    
        $proc = proc_open($command, $descriptor_spec, $pipes);
    
        if (is_resource($proc)) {
            $output = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
    
            $output = array_filter(array_map('trim', explode("\n", $output)));
            $stderr = array_filter(array_map('trim', explode("\n", $stderr)));
            $stderr = preg_filter('/^/', 'System error - ', $stderr);

            $return = array_merge($output, $stderr);
            proc_close($proc);
        } else {
            exit('System error - proc_open is not resource.');
        }

        return $return;
    }
}

$mysqldump = new MySQLDump();
$mysqldump->execute();