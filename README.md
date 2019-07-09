# Serverless Webhook Logger

There are times where you know your application will receive some webhooks but ahead
of time you may not know the range of values or possibilities. Alternatively you might
not want to expose a route in your own application but instead to process records from
a decoupled source.

This project uses AWS Lambda (via [Bref PHP](https://bref.sh)), API Gateway and DynamoDB to provide a simple
tool that can log inbound webhooks. You could use this for analysis, or via a stream
into another tool to process them.

## Existing tools

* [AWS CLI](https://docs.aws.amazon.com/cli/latest/userguide/cli-chap-install.html)
* [AWS SAM](https://aws.amazon.com/serverless/sam/)
* [Terraform](https://www.terraform.io/downloads.html)
* Some local AWS credentials ([AWS Vault](https://github.com/99designs/aws-vault) is recommended)

## Pre-install

Replace the following strings wherever they appear in the project:

* `YOUR_REGION_HERE` choose your desired AWS region (e.g. eu-west-1)
* `YOUR_BUCKET_HERE` choose a globally unique bucket name to host your code (e.g. my-company-webhook-logger-abc123)

Note when running all commands below that touch AWS you'll need AWS credentials in your environment.
There are a variety of ways to do this; using vault (e.g. `aws-vault exec YOUR_PROFILE -- terraform plan`) is recommended

## Install

You must first create resources via Terraform before deploying the application stack:

```
terraform init
terraform plan
terraform apply
```

Type "yes" after the Terraform apply step. Once Terraform runs it will output an "ARN" of a role that the
serverless application will need:

```
webhook-logger-role-arn = arn:aws:iam::XXXX:role/webhook-logger
```

Copy this "ARN" over the string `YOUR_ROLE_HERE` in `template.yml`

Now run `bin/deploy` which executes a series of AWS SAM commands to upload your code and deploy the function.
At the end of the run it will output as follows:

```
Successfully created/updated stack - webhook-logger
https://xxxxxxx.execute-api.YOUR_REGION_HERE.amazonaws.com/Prod/
```

NB if you miss the output run `bin/output` to see it again without deploying the stack.

If you run a request to that endpoint you should see the result logged:

```
curl -v -XPOST -H 'Content-type: application/json' -d '{"test":"abc123"}' 'YOUR_URL_HERE'
```

Go to the [DynamoDB console](https://eu-west-1.console.aws.amazon.com/dynamodb/home) and view the table "webhook-logger"
to see logs. If this fails check the [Cloudwatch console](https://eu-west-1.console.aws.amazon.com/cloudwatch/home)
where a log group like `/aws/lambda/webhook-logger` should exist (remember to choose the correct region in the top bar
of the console) with a stream for each series of invocations.

## Acknowledgements

The basis of this function came from the [Bref documentation for HTTP endpoints](https://bref.sh/docs/runtimes/http.html)

### Related

This project uses [m1ke/json-explore](https://github.com/M1ke/php-json-explore) to create a parsed set of
keys found in the JSON sent via webhook. This library has other uses for API exploration, and different methods
for rendering the data it analyses about a JSON payload.
