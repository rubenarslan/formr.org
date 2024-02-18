import os
from requests import get

formr = "timed-and-secured-assets/formr.org"
opencpu = "timed-and-secured-assets/opencpu"

#Get newest Release
releases = get("https://api.github.com/repos/"+formr+"/releases").json()
if(len(releases) > 0):
    version = releases[0]["tag_name"]
else:
    version = "master-snapshot"

#Docker Images
formr_docker_image = "ghcr.io/"+formr+":"+version
opencpu_docker_image = "ghcr.io/"+opencpu+":"+version
db_docker_image = "mysql:latest"

#Github-Links
repo_url = "https://github.com/"+formr
github_raw_generic_settings_url = "https://raw.githubusercontent.com/"+formr+"/feature/anleitung/config-dist/settings.php"
github_raw_create_user_url = "https://raw.githubusercontent.com/"+formr+"/feature/anleitung/run/create-formr-user.sql"
github_raw_generic_docker_compose_url = "https://raw.githubusercontent.com/"+formr+"/feature/anleitung/run/generic-docker-compose.yaml"
github_raw_sql_schema_url = "https://raw.githubusercontent.com/"+formr+"/feature/anleitung/sql/schema.sql"

#Script Settings
dir_name = "formr_docker"

#Functions
def createDockerCompose(db_root_passwd):
    compose_text = get(github_raw_generic_docker_compose_url).text
    #compose_text = open('../generic-docker-compose.yaml', 'r').read()
    compose_text = compose_text.replace('<$DB_ROOT_PASSWORD>', db_root_passwd)
    compose_text = compose_text.replace('<$DB_IMAGE>', db_docker_image)
    compose_text = compose_text.replace('<$FORMR_IMAGE>', formr_docker_image)
    compose_text = compose_text.replace('<$OPENCPU_IMAGE>', opencpu_docker_image)
    open('docker-compose.yaml', 'a').write(compose_text)

def createSettingsPhp(smtp_host, smtp_send_from, smtp_send_name, smtp_user, smtp_password, db_password, referrer_code, db_host, opencpu_url):
    setting_text = get(github_raw_generic_settings_url).text
    #setting_text = open('../genericSettings.php', 'r').read()
    setting_text = setting_text.replace('<$DB_HOST>', db_host)
    setting_text = setting_text.replace('<$DB_USERNAME>', 'formr')
    setting_text = setting_text.replace('<$DB_PASSWORD>', db_password)
    setting_text = setting_text.replace('<$OPEN_CPU_URL>', opencpu_url)
    setting_text = setting_text.replace('<$SMTP_HOST>', smtp_host)
    setting_text = setting_text.replace('<$SMTP_SEND_FROM>', smtp_send_from)
    setting_text = setting_text.replace('<$SMTP_SEND_NAME>', smtp_send_name)
    setting_text = setting_text.replace('<$SMTP_USER>', smtp_user)
    setting_text = setting_text.replace('<$SMTP_PASSWORD>', smtp_password)
    setting_text = setting_text.replace('<$REFERRER_CODE>', referrer_code)
    open('settings.php', 'a').write(setting_text)


def createUserSQL(db_password):
    create_user_text = get(github_raw_create_user_url).text
    #create_user_text = open('../create-formr-user.sql').read()
    create_user_text = create_user_text.replace('<$DB_PASSWORD>', db_password)
    open('create_user.sql', 'a').write(create_user_text)

def downloadSchemaSQL():
    schema_text = get(github_raw_sql_schema_url).text
    open('schema.sql', 'a').write(schema_text)


###
### Dialog
###
    
os.system("clear")

print("""
    ____                         
   / __/___  _________ ___  _____
  / /_/ __ \/ ___/ __ `__ \/ ___/
 / __/ /_/ / /  / / / / / / /    
/_/  \____/_/  /_/ /_/ /_/_/     
      
Create Config Script
""")

print("""
This script will create the config files, that are nessesary to run former with docker. 
For more Information or in case you want to customise Formr in a more spesific way,
you can have a look here: """ + repo_url + """

----------------- 
""")

print("""
Formr depends on two services: An opencpu instance with formr dependencies and a mysql Database.
In the following you can chose for each of these services, if you would like to run them as part of the Docker-Compose and what Docker-Image you would like to use
or if you would rather host them by yourself.
""")

print("Which Images would you like to use? If you just press Enter, the default will be selected \n")
#Images
formr_docker_image = input("First wihich Formr Docker Image would you like to use (Default: "+formr_docker_image+"): ") or formr_docker_image
opencpu_in_docker = input("\nWould you like to run OpenCpu as part of the Docker-Compose [Y/N] :") == "Y"
if(opencpu_in_docker):
    opencpu_docker_image = input("OpenCpu Docker Image (Default: "+opencpu_docker_image+"): ") or opencpu_docker_image
db_in_docker = input("\nWould you like to run Mysql as part of the Docker-Compose [Y/N] :") == "Y"
if(db_in_docker):
    db_docker_image = input("Mysql Docker Image (Default: "+db_docker_image+"): ") or db_docker_image

#referrer code  referrer_code
referrer_code = input("\nReferrer-Code (Code that enables one to become a admin while signin up ): ")

#Passwords
print("")
if(db_in_docker):
    db_root_passwd = input("DB Root - Password: ")
    db_host = 'formr_db'
else:
    db_root_passwd = ""
    db_host = input("DB - Host: ")
db_password = input("DB Formr User - Password: ")


print("\nNow we need to configure the SMTP Client of formr \n")
#Email
smtp_host = input("SMTP Host: ")
smtp_user = input("SMTP User: ")
smtp_password = input("SMTP Password: ")
smtp_send_from = input("Send from Email: ")
smtp_send_name = input("Send with Name: ")

if(opencpu_in_docker):
    opencpu_url = 'http://opencpu:5656'
else:
    opencpu_url = input("\nOpenCpu - Url: ")

#Skript Setting
dir_name = input("\nInstall Dir (Default: "+dir_name+"): ") or dir_name


###
### Create Config Files
###
if(input("Do yout want to proceed ? [Y] ") == "Y"):
    #Create new Folder
    if not os.path.exists(dir_name):
        os.mkdir(dir_name)
    os.chdir(dir_name)
    createDockerCompose(db_root_passwd)
    createSettingsPhp(smtp_host, smtp_send_from, smtp_send_name, smtp_user, smtp_password,db_password,referrer_code, db_host, opencpu_url)
    createUserSQL(db_password)
    downloadSchemaSQL()

###
### Whats next
###
    
print("""
--------------------
      
Now you should find a create_user.sql, schema.sql, a docker-compose.yaml and a settings.php inside the """+dir_name+""" directory. 
""")
if(not db_in_docker):
    print("\nThe docker-compose.yaml still contains the formr_db service. You need to delete that!")
    print("And before you can run formr you need to run the schema.sql and the cerate_user.sql (in that Order) on your DB.")

if(not opencpu_in_docker):
    print("\nThe docker-compose.yaml still contains the opencpu service. You need to delete that!")

print("""
This script only sets the minimum of configurations, which are nessesary to run formr. We highly recommend, that you go through the hole settings.php - especially if you
are hosting formr for production.
      
-------------------
### What's next?
-------------------
# Starting the application

    To start the docker compose you need to go into the """+dir_name+""" directory and run

            docker-compose up

    Now you can access formr under http://localhost/formr

    And you can acces the terminal of formr via: docker-compose exec formr_server bash
# Stopping the application

    To stop the docker compose you need to go into the """+dir_name+""" directory and run

            docker-compose stop

""")