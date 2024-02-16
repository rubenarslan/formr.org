import os
from requests import get

#Docker Images
formr_docker_image = "ghcr.io/timed-and-secured-assets/formr.org:master"
opencpu_docker_image = "ghcr.io/timed-and-secured-assets/formr.org:master"
mysql_docker_image = "mysql:latest"

#Github-Links
repo_url = "https://github.com/timed-and-secured-assets/formr.org"
github_raw_generic_settings_url = "https://raw.githubusercontent.com/timed-and-secured-assets/formr.org/feature/anleitung/config-dist/settings.php" 
github_raw_create_user_url = ""
github_raw_generic_docker_compose_url = ""
github_raw_sql_schema_url = ""

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

#Download generic_settings
r = get(github_raw_generic_settings_url)
print(r.content)
open('settings.php', 'a').write(r.text)

#Download generic compose
r = get(github_raw_generic_docker_compose_url)
print(r.content)
open('docker-compose.yaml', 'a').write(r.text)

#Download generic create user sql
r = get(github_raw_generic_docker_compose_url)
print(r.content)
open('create_user.sql', 'a').write(r.text)

#Download sql schema
r = get(github_raw_sql_schema_url)
print(r.content)
open('schema.sql', 'a').write(r.text)

