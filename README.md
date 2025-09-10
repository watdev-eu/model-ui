# WATDEV model UI

A user interface to interact with WATDEV models, view live app at [github.io](https://watdev-eu.github.io/model-ui)

The project [WATDEV](https://watdev.eu)  - Climate Smart WATer Management and Sustainable DEVelopment for Food and Agriculture in East Africa is funded by the DeSIRA initiative of the European Union and aims to develop an in-depth understanding of small and large-scale water and agricultural resource dynamics and management while boosting people’s resilience to climate, through innovative research, modelling, and capacity building approaches.

## Authentication

A simple authentication has been added using [auth0](https://auth0.com/) services. Alternatives here are the authentication services provided by [Surf](https://www.surf.nl/en/services/identity-access-management/surfconext) or a authentication provided by the modelling backend.

## Inputs

Potentially 2 types of users exist, advanced users, which can update advanced parameters on the model and `policy users` which have a basic set of configuration options.

## Modelling backend

Idea is to directly trigger the modelling backend and wait for the result. Alternatively the backend may respond with a queue token, and we verify at intervals if the process is finished using the token. Or a model-UI server component maintains a queue and the modelling backend polls the queue at times to understand if there are pending modelling tasks. 

## Post processing

After the modelling backend is finished, some cost-benefit analysis post processing needs to occur, before the result is presented in the front-end. Some of the parameters may be changed on the UI, which only require post processing to be run again, without a full model run. To be investigated how the post processing accesses the various model run results (will there be a central database, or will the model-ui send the required results to post processing).

```mermaid
flowchart LR
    A(Model UI) --> B(Model)
    A --> P(Post Processing)
    A --> DB[(Database)]
```

vs

```mermaid
flowchart LR
    A(Model UI) --> B(Model)
    A --> DB[(Database)]
    B --> DB
    A --> P(Post Processing)
    DB --> P
```

Post processing calculates optimisation based on indicator weight. Model-UI should present for a set of indicators (yield, financial return, water availability) a weight scale how important the factor is for the user. And trigger post-processing again for a given model run (or compare 2 scenario's).

## Backend Delivery in phases

Seems the model backend will be delivered in phases (up to 2026), for each target-area the model needs to be configured separately with relevant source data. Egypt is likely the first area available. Suggestion would be to create mock responses for the other areas, so the ui development is not delayed. The model-ui should clearly indicate the status of the model backend, when selecting a region.

## Glossary

- HRU modelling area (fieldscale or region area, river catchment)
- BMP scenario 
- Indicators (cost/benefit, yield, )
- KPI - Key performance indicators

---

​The [WATDEV project](https://capacity4dev.europa.eu/projects/desira/info/watdev_en) is maintained with the financial support of the European Union. Its contents are the sole responsibility of the authors and do not necessarily reflect the views of the European Union.
