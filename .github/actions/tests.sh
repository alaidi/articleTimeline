#!/bin/bash

# The plugin's own tests inside PKP's continuous integration (sourced by
# pkp-github-actions from the OJS root): the browser tests first, then the unit
# tests. Neither may be skipped because the other failed, so the status of both
# is kept and the worst one is returned.

set +e
status=0

npx cypress run  --headless --browser chrome  --config '{"specPattern":["plugins/generic/articleTimeline/cypress/tests/functional/*.cy.js"]}'
cypress=$?
[ $cypress -ne 0 ] && status=$cypress

php lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage plugins/generic/articleTimeline/tests
phpunit=$?
[ $phpunit -ne 0 ] && status=$phpunit

echo "tests.sh: cypress=$cypress phpunit=$phpunit"
return $status
