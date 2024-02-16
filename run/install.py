import os
from requests import get

#Docker Images
formr_docker_image = "ghcr.io/timed-and-secured-assets/formr.org:master"
opencpu_docker_image = "ghcr.io/timed-and-secured-assets/formr.org:master"
db_docker_image = "mysql:latest"

#Github-Links
repo_url = "https://github.com/timed-and-secured-assets/formr.org"
github_raw_generic_settings_url = "https://raw.githubusercontent.com/timed-and-secured-assets/formr.org/feature/anleitung/run/genericSettings.php"
github_raw_create_user_url = "https://raw.githubusercontent.com/timed-and-secured-assets/formr.org/feature/anleitung/run/create-formr-user.sql"
github_raw_generic_docker_compose_url = "https://raw.githubusercontent.com/timed-and-secured-assets/formr.org/feature/anleitung/run/generic-docker-compose.yaml"
github_raw_sql_schema_url = "https://raw.githubusercontent.com/timed-and-secured-assets/formr.org/feature/anleitung/sql/schema.sql"

#Script Settings
dir_name = "formr_run"
db_root_passwd = "passwd"
db_password = "passwd"


###
### Script
###

print("Welcome to the formr install script \n")

#repo_url: str = input("Github Url of the formr Repository: ")


#Create new Folder
if not os.path.exists(dir_name):
    os.mkdir(dir_name)

os.chdir(dir_name)

#Download generic_settings and replace Env
setting_text = get(github_raw_generic_settings_url).text

open('settings.php', 'a').write(setting_text)

#Download generic compose and replace Env
compose_text = get(github_raw_generic_docker_compose_url).text
compose_text.replace('<$DB_ROOT_PASSWORD>', db_root_passwd)
compose_text.replace('<$DB_IMAGE>', db_docker_image)
compose_text.replace('<$FORMR_IMAGE>', formr_docker_image)
compose_text.replace('<$OPENCPU_IMAGE>', opencpu_docker_image)
open('docker-compose.yaml', 'a').write(compose_text)

#Download generic create user sql
create_user_text = get(github_raw_create_user_url).text
open('create_user.sql', 'a').write(create_user_text)

#Download sql schema
schema_text = get(github_raw_sql_schema_url).text
open('schema.sql', 'a').write(create_user_text)

