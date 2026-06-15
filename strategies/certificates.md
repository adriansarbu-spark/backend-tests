# CRL test certificates

No local issuer certificate fixture is required.

Leaf certificates are downloaded at runtime from **TEST_USER_1** via the account certificates API. The helper then:

1. Reads **CRL Distribution Points** from the downloaded leaf certificate and fetches the CRL.
2. Reads the **CRL issuer** from that CRL and downloads the matching CA certificate from the same PKI path (for example `.crt` alongside `.crl`).
3. Uses that CA certificate to verify the CRL signature and check revocation status.
