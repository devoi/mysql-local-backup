1. __database.ini DB 정보 확인__

2. __settings.ini 로컬 및 슬랙 훅 URL 확인__

3. __PHP 7.3.27 다운로드 후 환경변수 등록__

    - https://windows.php.net/downloads/releases/archives/
    - php-7.3.27-Win32-VC15-x64.zip 설치
    - php.ini 파일 생성
    - extension_dir = "ext"
    - curl, fileinfo, gd2, intl, mbstring, mysqli, openssl, pdo_mysql 등 필요한 라이브러리 주석 제거
<br />

4. __MySQL 5.7.33 다운로드 후 환경변수 등록__

    - username/password : root/root
    - https://downloads.mysql.com/archives/installer/
    - mysql-installer-community-5.7.33.0.msi 설치
<br />

5. __mysqldump.bat, MySQLDump.php 같은 경로 내에 위치__

6. __mysqldump.bat 파일 작업 스케줄러 등록__

<br />

---

<br />

__※__ 작업 스케줄러 등록 시,<br />
　 MySQLDump.php -> `$root_path` / mysqldump.bat -> `실행파일경로` 절대 경로로 지정

__※__ 환경변수 등록 후 커맨드 실행할 때,<br />
　 프로세스가 무한히 생성 된다면, 명령어에 `.exe` 확장자 붙여서 실행

__※__ 복구 시, `<` 연산자 예약어로 되어있는 경우<br />
　 Get-Content restore.sql | mysql -u USERNAME -pPASSWORD -h HOST DBNAME

__※__ 백업 시, `^` 등 exec 함수에서 사용안되는 문자의 경우<br />
　 `^^` 등 escape 처리해서 사용

__※__ database.ini 내용 예시
```ini
   ; --- 예시 ---
   [backup01]
   host = localhost
   username = root
   password = root
   database[0] = db_name1
   database[1] = db_name2
   ignore-table[1] = table_name1,table_name2
```

__※__ settings.ini 내용 예시
```ini
   slack_hook_url = "https://hooks.slack.com/services/*****/*****"
   local_username = "root"
   local_password = "root"
   local_host = "localhost"
```
