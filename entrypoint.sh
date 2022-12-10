#!/bin/sh

if [ "$DATABASE" = "postgres" ]
then
    echo "Waiting for postgres..."
    while ! nc -z $SQL_HOST $SQL_PORT; do
        sleep 0.5
    done
    echo "PostgreSQL started"
fi

LOGS_DIR="/home/app/web/logs/"
MYPY_LOG="${LOGS_DIR}mypy_log.log"
FLAKE8_LOG="${LOGS_DIR}flake8_log.log"
UNITTESTS_LOG="${LOGS_DIR}unittests_log.log"
touch $MYPY_LOG $FLAKE8_LOG $UNITTESTS_LOG &&
chmod 666 $FLAKE8_LOG $MYPY_LOG $MYPY_LOG &&
echo "\n--------$(date)-COMPOSE-RELOAD--------\n" | tee -a $MYPY_LOG $FLAKE8_LOG $UNITTESTS_LOG &&
python manage.py test >> $UNITTESTS_LOG 2>&1 &&
flake8 ./mainapp >> $FLAKE8_LOG 2>&1 &&
mypy ./mainapp >> $MYPY_LOG 2>&1 &&
python manage.py flush --no-input &&
python manage.py migrate &&
# Create user for e2e testing
python manage.py shell < user_check.py &&
echo "--------USERS-CREATED--------" &&
# Create mock data for e2e testing
python manage.py shell < mock_database.py &&
echo "--------MOCK-DATA-ADDED--------" &&
python manage.py runserver 0.0.0.0:8000

exec "$@"