#!/usr/bin/env bash
# Would have caught the original bug: category list must show a product edit.
BASE=${BASE:-http://localhost:8080}

curl -s $BASE/categories/1/products > /dev/null            # warm the cache
curl -s -X PUT $BASE/products/1 -H "Content-Type: application/json" \
     -d '{"name":"Regression Test Name"}' > /dev/null      # edit product

if curl -s $BASE/categories/1/products | grep -q "Regression Test Name"; then
  echo "PASS"; RESULT=0
else
  echo "FAIL: category list is stale"; RESULT=1
fi

curl -s -X PUT $BASE/products/1 -H "Content-Type: application/json" \
     -d '{"name":"Airbus H125"}' > /dev/null               # restore original name
exit $RESULT