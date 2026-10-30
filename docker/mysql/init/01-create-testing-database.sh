#!/bin/sh
set -eu

mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${MYSQL_TEST_DATABASE}\`;
GRANT ALL PRIVILEGES ON \`${MYSQL_TEST_DATABASE}\`.* TO '${MYSQL_USER}'@'%';
CREATE DATABASE IF NOT EXISTS \`habit_log_recovery_test\`;
GRANT ALL PRIVILEGES ON \`habit_log_recovery_test\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
