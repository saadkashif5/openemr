"""Stop every running server and database of this project. Called when the monthly budget is reached.

Stopping keeps all data (disks and database storage remain and are billed at their small storage rate).
Compute stops billing. To resume: start the EC2 instance and the RDS instance again.
Note that AWS restarts a stopped RDS instance by itself after 7 days.
"""
import os

import boto3

PROJECT = os.environ["PROJECT"]


def handler(event, context):
    ec2 = boto3.client("ec2")
    rds = boto3.client("rds")
    stopped = {"ec2": [], "rds": []}

    reservations = ec2.describe_instances(
        Filters=[
            {"Name": "tag:Project", "Values": [PROJECT]},
            {"Name": "instance-state-name", "Values": ["pending", "running"]},
        ]
    )["Reservations"]
    ids = [i["InstanceId"] for r in reservations for i in r["Instances"]]
    if ids:
        ec2.stop_instances(InstanceIds=ids)
        stopped["ec2"] = ids

    for db in rds.describe_db_instances()["DBInstances"]:
        tags = {t["Key"]: t["Value"] for t in rds.list_tags_for_resource(ResourceName=db["DBInstanceArn"])["TagList"]}
        if tags.get("Project") == PROJECT and db["DBInstanceStatus"] == "available":
            rds.stop_db_instance(DBInstanceIdentifier=db["DBInstanceIdentifier"])
            stopped["rds"].append(db["DBInstanceIdentifier"])

    print("Budget limit reached, stopped:", stopped)
    return stopped
