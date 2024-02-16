import os
from requests import get

#Docker Images
formr_docker_image = "ghcr.io/timed-and-secured-assets/formr.org:master"
opencpu_docker_image = "ghcr.io/timed-and-secured-assets/opencpu:master"
db_docker_image = "mysql:latest"

#Github-Links
repo_url = "https://github.com/timed-and-secured-assets/formr.org"
github_raw_generic_settings_url = "https://raw.githubusercontent.com/timed-and-secured-assets/formr.org/feature/anleitung/run/genericSettings.php"
github_raw_create_user_url = "https://raw.githubusercontent.com/timed-and-secured-assets/formr.org/feature/anleitung/run/create-formr-user.sql"
github_raw_generic_docker_compose_url = "https://raw.githubusercontent.com/timed-and-secured-assets/formr.org/feature/anleitung/run/generic-docker-compose.yaml"
github_raw_sql_schema_url = "https://raw.githubusercontent.com/timed-and-secured-assets/formr.org/feature/anleitung/sql/schema.sql"

#Script Settings
dir_name = "formr_run"
db_root_passwd = 'passwd'
db_password = 'passwd'

#Functions
def createDockerCompose():
    compose_text = get(github_raw_generic_docker_compose_url).text
    #compose_text = open('../generic-docker-compose.yaml', 'r').read()
    compose_text = compose_text.replace('<$DB_ROOT_PASSWORD>', db_root_passwd)
    compose_text = compose_text.replace('<$DB_IMAGE>', db_docker_image)
    compose_text = compose_text.replace('<$FORMR_IMAGE>', formr_docker_image)
    compose_text = compose_text.replace('<$OPENCPU_IMAGE>', opencpu_docker_image)
    open('docker-compose.yaml', 'a').write(compose_text)

def createSettingsPhp(smtp_host, smtp_send_from, smtp_send_name, smtp_user, smtp_password):
    setting_text = get(github_raw_generic_settings_url).text
    #setting_text = open('../genericSettings.php', 'r').read()
    setting_text = setting_text.replace('<$DB_HOST>', 'formr_db')
    setting_text = setting_text.replace('<$DB_USERNAME>', 'formr')
    setting_text = setting_text.replace('<$DB_PASSWORD>', db_password)
    setting_text = setting_text.replace('<$OPEN_CPU_URL>', 'http://opencpu:5656')
    setting_text = setting_text.replace('<$SMTP_HOST>', smtp_host)
    setting_text = setting_text.replace('<$SMTP_SEND_FROM>', smtp_send_from)
    setting_text = setting_text.replace('<$SMTP_SEND_NAME>', smtp_send_name)
    setting_text = setting_text.replace('<$SMTP_USER>', smtp_user)
    setting_text = setting_text.replace('<$SMTP_PASSWORD>', smtp_password)
    open('settings.php', 'a').write(setting_text)


def createUserSQL():
    create_user_text = get(github_raw_create_user_url).text
    #create_user_text = open('../create-formr-user.sql').read()
    create_user_text = create_user_text.replace('<$DB_PASSWORD>', db_password)
    open('create_user.sql', 'a').write(create_user_text)

def downloadSchemaSQL():
    schema_text = get(github_raw_sql_schema_url).text
    open('schema.sql', 'a').write(schema_text)


###
### Script
###

#Dialog
print("Welcome to the formr install script \n")

print("Which Images would you like to use? If you just press Enter, the default will be selected \n")
#Images
formr_docker_image = input("Formr Docker Image (Default: "+formr_docker_image+"): ") or formr_docker_image
opencpu_docker_image = input("OpenCpu Docker Image (Default: "+opencpu_docker_image+"): ") or opencpu_docker_image
db_docker_image = input("Mysql Docker Image (Default: "+db_docker_image+"): ") or db_docker_image

print("Now we want to configure the SMTP Client \n")
#Email
smtp_host = input("SMTP Host: ")
smtp_user = input("SMTP User: ")
smtp_password = input("SMTP Password: ")
smtp_send_from = input("Send from Email: ")
smtp_send_name = input("Send with Name: ")


#repo_url: str = input("Github Url of the formr Repository: ")

if(input("Do yout want to proceed ? [Y] ") == "Y"):

#Create new Folder
    if not os.path.exists(dir_name):
        os.mkdir(dir_name)
    os.chdir(dir_name)
    createDockerCompose()
    createSettingsPhp(smtp_host, smtp_send_from, smtp_send_name, smtp_user, smtp_password)
    createUserSQL()
    downloadSchemaSQL()
