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
* [oss-serverless](https://github.com/oss-serverless/serverless) (`osls`)
* [jq](https://jqlang.org) (only for `bin/import`)
* Some local AWS credentials ([AWS Vault](https://github.com/99designs/aws-vault) is recommended)

Note when running all commands below that touch AWS you'll need AWS credentials in your environment.
There are a variety of ways to do this; using aws-vault (e.g. `aws-vault exec YOUR_PROFILE -- bin/deploy dev`) is recommended

## Install

All resources (DynamoDB table, IAM role, Lambda function and API Gateway) are defined in `serverless.yml`.
Create a `.env.dev` file containing `ENV_RELAY` (a JSON relay config, `{}` for none), then for a fresh install run:

```
osls deploy --stage=dev
```

Subsequent deploys use `bin/deploy dev`, which only updates an existing stack.

### Migrating from Terraform

Earlier versions created the DynamoDB table and IAM role with Terraform. To adopt these into an existing
stack without recreating them, run `bin/import dev` once instead of `bin/deploy`. It imports both resources
into the CloudFormation stack, deploys, then removes the old Terraform-named inline policy from the role.
Afterwards the Terraform state can be discarded (do not run `terraform destroy`).

At the end of a deploy it will output the endpoint:

```
endpoint: ANY - https://xxxxxxx.execute-api.YOUR_REGION_HERE.amazonaws.com/dev
```

NB if you miss the output run `bin/output` to see it again without deploying the stack.

If you run a request to that endpoint you should see the result logged:

```
curl -v -XPOST -H 'Content-type: application/json' -d '{"test":"abc123"}' ' https://xxxxxxx.execute-api.eu-west-1.amazonaws.com/dev'
```

Go to the [DynamoDB console](https://eu-west-1.console.aws.amazon.com/dynamodb/home) and view the table "webhook-logger"
to see logs. If this fails check the [Cloudwatch console](https://eu-west-1.console.aws.amazon.com/cloudwatch/home)
where a log group like `/aws/lambda/webhook-logger` should exist (remember to choose the correct region in the top bar
of the console) with a stream for each series of invocations.

### Modifying

Some config items can be changed easily in `config.php`

## Acknowledgements

The basis of this function came from the [Bref documentation for HTTP endpoints](https://bref.sh/docs/runtimes/http.html)

### Related

This project uses [m1ke/json-explore](https://github.com/M1ke/php-json-explore) to create a parsed set of
keys found in the JSON sent via webhook. This library has other uses for API exploration, and different methods
for rendering the data it analyses about a JSON payload.
