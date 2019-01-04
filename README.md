# Rowan Univeristy Library API

This is a lightweight API that is used to provide a basic framework to intergrate library services and information with
3rd party applications.

This application was developed utilizing the Silex Framework and custom components from the Symfony framework

# Current End Points

Currently there are two (2) endpoints avaialble via this api, but given the achitecture we can add more as needed.

## Voyager Item Status

**GET /v1/summonrta?bibid=[bibid]**

This end point recieved a bibid for an book and returns and JSON formatted list of item status

This end point utilizes the VoyagerService component in `src/Services/VoyagerService.php`.  This service retrieves items status from the voayger
database so that they can be returned as a JSON result.

## EZProxy Host Configuration Error Report Submission

**POST /ezproxy/sendreport**

Receives a post body, parses the data and submits the information to our support ticket system.

The origin of the POST request is submitted from the EZProxy Host Configuration Error Page. Users who recieve a host configuration
have the option to report the error utilizing ReactJS form component.

This endpoint will only access valid request from users whose email address are withing the rowan.edu or cooperhealth.edu domains

# Configuration

Application configuration can be found in `/app/config`. Simply copy config.yaml.dist to your desired environment config file as follows.

The config that is loaded depends on how you set the APP_ENV envirnment variable in your host configuration

## APP_ENV=local

If APP_ENV=local, then name your config `config.local.yml`

## APP_ENV=devel (default)

If APP_ENV=devel, then name your config `config.devl.yml`

## APP_ENV=prod   

If APP_ENV=prod, then name your config `config.prod.yml`

# Configuration Options

## Voyager Endpoint Configurations

- `dbs` - this section of the config file defines the databases that can be connected to
  - `voyager.read` - define the connection parameters for the Voyager read only databases
    - `user` - username for connection,
    - `password` - password for connection,
    - `host` - hostname for connection,
    - `dbname` - dbname for connection,
    - `servicename` - oracle service name,
    - `service` -  connect as service true or face, default false

- `voyager` - this section defines some additional helper and label translations
  - `dbname` - the name of the DB to be used in queries
  - `item_status` - defines item status label translations 
    - `Not Charged` - translate voyager 'Not Charted' label to specified value, default 'Available'
    - `Charged` - tranlate voyager 'Charged label to specified value, default 'Checked Out'
  - `reserve_status` - this section defines item reserve message lables 
      - `message`: Sets the message for items that are current on reserver, default 'On Reserve'

## EZProxy Endpoint Configurations

- `ezproxy.report` - defines configurations for ezproxy error reporting endpoint
  - `subject` - defines the outgoing email subject
  - `to` - defines the email address to send the report to
  - `origins` - entries under this section define the hosts where submissions are allowed from (entries are in YAML array format)
    - `- host.domain.tld`
    - `- host2.domain.tld`
    

