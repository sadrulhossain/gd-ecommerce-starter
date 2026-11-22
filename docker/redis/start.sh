#!/bin/sh
# Writes the ACL file from env at start (no secret in the image or in a committed file), then runs redis-server.
# default user is off; "app" may do everything except the dangerous commands.
set -eu
: "${REDIS_PASSWORD:?set REDIS_PASSWORD}"
umask 077
cat > /tmp/users.acl <<ACL
user default off
user app on >${REDIS_PASSWORD} ~* &* +@all -@dangerous +flushdb -restore -debug -module -config
ACL
exec redis-server --aclfile /tmp/users.acl --appendonly yes --save "" --dir /data
