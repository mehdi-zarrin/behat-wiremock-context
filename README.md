Example of configuration
```yaml
- MTZ\BehatContext\Wiremock\WiremockContext:
      baseUrl: 'http://wiremock'
      stubsDirectory: '%paths.base%/features/stubs'
```
Note: if you have installed wiremock using docker then the service name
that you used in the docker compose will be the base url.
in this case the assumption is the docker service name is "wiremock"
therefore the baseUrl will be "http://wiremock"
