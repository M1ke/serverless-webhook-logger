provider "aws" {
  region = "${var.region}"
}

resource "aws_dynamodb_table" "webhook-logger" {
  name = "webhook-logger"

  billing_mode = "PAY_PER_REQUEST"

  hash_key = "id"
  range_key = "datetime"

  attribute {
    name = "id"
    type = "S"
  }

  attribute {
    name = "datetime"
    type = "S"
  }

  ttl {
    attribute_name = "ttl"
    enabled        = true
  }
}

resource "aws_iam_role" "webhook-logger" {
  name = "webhook-logger"
  path = "/"
  assume_role_policy = <<POLICY
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": {
        "Service": "lambda.amazonaws.com"
      },
      "Action": "sts:AssumeRole"
    }
  ]
}
POLICY
}

resource "aws_iam_role_policy" "webhook-logger" {
  role = "${aws_iam_role.webhook-logger.name}"
  policy = <<POLICY
{
  "Version": "2012-10-17",
  "Statement": [
  {
      "Sid": "DynamoWrite",
      "Effect": "Allow",
      "Action": [
        "dynamodb:DeleteItem",
        "dynamodb:DescribeTable",
        "dynamodb:GetItem",
        "dynamodb:PutItem",
        "dynamodb:Query",
        "dynamodb:Scan",
        "dynamodb:UpdateItem"
      ],
      "Resource": [
        "${aws_dynamodb_table.webhook-logger.arn}"
      ]
    },
    {
      "Sid": "Logging",
      "Effect": "Allow",
      "Action": [
        "logs:CreateLogGroup",
        "logs:CreateLogStream",
        "logs:PutLogEvents"
      ],
      "Resource": "*"
    }
  ]
}
POLICY
}

output "webhook-logger-role-arn" {
  value = "${aws_iam_role.webhook-logger.arn}"
}
