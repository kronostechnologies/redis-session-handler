BASE_DIR := $(dir $(realpath $(firstword $(MAKEFILE_LIST))))
OS_TYPE := $(shell uname -s)
USER_ID := $(shell id -u)
GROUP_ID := $(shell id -g)
MKDIR_P = mkdir -p
DOCKER_PHP = docker run -it --rm \
	-v $(BASE_DIR):/home/circleci/project \
    -v ~/.cache:/home/circleci/.cache --user "${USER_ID}:${GROUP_ID}" \
    --workdir /home/circleci/project \
    -e HOME=/home/circleci \
    ghcr.io/kronostechnologies/php:7.4-node

.PHONY: all setup check psalm

all: setup check

setup:
	@composer install

check: psalm

psalm:
	@${DOCKER_PHP} ./vendor/bin/psalm $(PSALM_ARGS)

.PHONY: bom
bom:
	@rm -f build/reports/bom.json
	@mkdir -p build/reports
	composer make-bom --output-format=JSON --output-file=build/reports/bom.json --no-interaction
