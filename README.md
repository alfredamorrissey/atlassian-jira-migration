# Jira Issue Sync Script

This script automates the migration of issues from a source Jira project to a target Jira project using the REST API. It supports copying summary, description, issue type, components, and custom fields like Consortium Jira ID and Components.

## 🚀 Features

* Creates new issues in the target project based on source project data.
* Copies key fields like summary, description, priority, components, status, fix version, labels.
* Syncs custom fields (e.g., Consortium Jira ID, Components, Fix Version, Reporter Name).
* Logs API responses for troubleshooting.

---

## ⚙️ Prerequisites

1. **Jira Admin Permissions**

   * Admin access to the target Jira Project, view access to the source Jira Project.

2. **PHP + cURL**

   * Ensure PHP and the cURL extension are installed and enabled.

---
## Getting Started

Clone the repository, change into the root folder of the project, and install the dependencies.

```bash
git clone git@github.com:alfredamorrissey/atlassian-jira-migration.git
cd atlassian-jira-migration
composer install
```
Copy the .env.example to .env, the values are explained below
```bash
cp .env.example .env
```
Populate your .env with the following:

  * JIRA_SOURCE_DOMAIN and JIRA_TARGET_DOMAIN
  * JIRA_SOURCE_PROJECT and JIRA_TARGET_PROJECT
---
## 🔐 Creating Your API Key

To authenticate with the Jira REST API, you’ll need to create a personal API token:

1. Go to [https://id.atlassian.com/manage/api-tokens](https://id.atlassian.com/manage/api-tokens).
2. Click **Create API token**.
3. Name your token (e.g., "Jira Sync Script") and click **Create**.
4. Copy the generated token. This is your only chance to view it.
5. Paste your email and api token into your .env

```.dotenv
JIRA_USERNAME=<your_email_token_here>
JIRA_API_TOKEN=<your_api_token_here>
```

**Note:** Do not commit your .env into git. Your .env should be in the .gitingnore

For documentation, see: [Atlassian API tokens](https://support.atlassian.com/atlassian-account/docs/manage-api-tokens-for-your-atlassian-account/)

---

## 🛠 Required Setup in Target Project

### 1. 🧹 Create Custom Fields

You must manually create the following custom fields in your **target Jira project**:
https://{domain}.atlassian.net/jira/software/projects/{targetProjectKey}/settings/fields

#### a. **Consortium Jira ID**

* **Type**: Text Field (single line)
* **Used for**: Storing the source issue key for reference.

#### b. **Components**

* **Type**: Labels
* **Used for**: Storing the names of the original components avoids having to create them in the target project.

#### c. **Fix Version**

* **Type**: Labels
* **Used for**: Storing the fix versions from the source project.

#### d. **Reporter Name**

* **Type**: Text Field (single line)
* **Used for**: Storing the original Reporter's name from the source issue. The author will not exist in the target project.

Once created, note down their **custom field IDs** (e.g., `customfield_12345`). You can find them by hitting the editmeta endpoint or using browser inspection tools.
https://{yourDomain}.atlassian.net/rest/api/3/issue/{issueKey}/editmeta

Update the .env file with your custom field values:

```.dotenv
CF_CONSORTIUM_JIRA_ISSUE=12385
CF_COMPONENTS=12419
CF_FIX_VERSION=12421
CF_REPORTER_NAME=12422
```
---
### 2. **Type Mappings**

#### a. **Issue Types**

* **Source Endpoint**: 
Get the source project id from: 
https://{{sourceDomain}}.atlassian.net/rest/api/3/project/{{sourceProjectKey}}
Get the source project issue types from: 
https://{{sourceDomain}}.atlassian.net/rest/api/3/issuetype/project?projectId=10100
* **Target Endpoint**: 
Get the target project id from: 
https://{{targetDomain}}.atlassian.net/rest/api/3/project/{{targetProjectKey}}
Get the target project issue types from: 
https://{{sourceDomain}}.atlassian.net/rest/api/3/issuetype/project?projectId={{targetProjectId}}

### b. **Issue Link Types**
* **Source Endpoint**: https://{{sourceDomain}}.atlassian.net/rest/api/3/issueLinkType
* **Target Endpoint**: https://{{targetDomain}}.atlassian.net/rest/api/3/issueLinkType

Verify that the default values will work with your target project:

```.dotenv
JIRA_TYPE_MAPPING='{"Epic":"Epic","Story":"Story","Task":"Task","Sub-task":"Subtask","Bug":"Bug","Support":"Task","}'
JIRA_LINK_TYPE_MAPPING='{"Blocks":"Blocks","Cloners":"Cloners","Duplicate":"Duplicate","Polaris issue link":"Polaris issue link","Problem/Incident":"Problem/Incident","QAlity Test":"Test","Relates":"Relates"}'
```
---

### 3. 🔄 Set Up Workflow 

To fully match the source project’s issue handling process, configure your target project’s workflow:

* Map the source project’s statuses (e.g., "To Do", "In Progress", "Done") to your target project’s workflow.
* If statuses like "Available" or "Backlog" exist in the source, ensure those transitions and statuses are available in the target.
* Add any necessary screens or field configurations so fields like `components`, `priority`, or custom fields are editable via the API.

**Consortium Workflow Reference:**

![Consortium Workflow](consortium_workflow.png)

#### Workflow States:

* New Issue
* In Progress
* Available
* In Review
* In Testing
* Ready to Merge
* Merged
* Done
* Closed

**New Project Workflow Reference:**

![New Project Workflow](our_workflow.png)

Note: In the new project, I allowed any status, to go to any status so that the script can move each issue to status of the original issue.

Note: Jira does not allow copying workflows directly via the API. You’ll need to manually replicate the workflow configuration or use Jira’s **shared workflow scheme** if applicable.

Documentation: [Jira Workflow Documentation](https://support.atlassian.com/jira-cloud-administration/docs/manage-your-workflows-in-jira-cloud/)

---

## 📄 How to Use

1. **Run the sync script**
    Depending on resources, running through the entire repository of issues (eg. Elentra has over 10,000) could take over 10 hours.
    Some options have been provided so you can run them through in batches at a time. The script should not duplicate issues, if the issue exists it will try to perform an update.
    It will try to valide if any attachments or links are already updated and only add new ones.
    Comments are difficult to search, so it will only add comments if the target issue doesn't yet have comments.
    If you want to speed up a second pass you can run with the --skip-existing option.

    ```bash
    php import_consortium_jira.php [options]
    ```

---
## ✅ Available Options
| Option            | Description                                                            |
| ----------------- | ---------------------------------------------------------------------- |
| `--key`           | Comma-separated list of issue keys to sync (e.g., `--key SRC-1,SRC-2`) |
| `--start`         | Start index of issues to sync (inclusive)                              |
| `--end`           | End index of issues to sync (inclusive)                                |
| `--batches`       | Number of batches to process (each batch uses `--batch-size`)          |
| `--batch-size`    | Number of issues to process per batch (default: 10)                    |
| `--skip-existing` | Skip issues that already exist in the target project                   |

---
## 👇 Examples

**Sync specific issues by key:**

  ```bash
  php import_consortium_jira.php --key=ME-123,ME-234
  ```
**Sync a specific range of issues:**

  ```bash
  php import_consortium_jira.php --start=0 --end=100
  ```
**Sync in batches:**

  ```bash
  php import_consortium_jira.php --start=0 --batches=5 --batch-size=100
  ```
**Skip existing issues to speed up the sync:**

  ```bash
  php import_consortium_jira.php --start=0 --skip-existing
  ```  
**You can execute your command directly with caffeinate to ensure the system stays awake for the duration of the process:**

  ```bash
  caffeinate -i php import_consortium_jira.php --start=0 --skip-existing
  ```  
**If you run into memory allocation errors you can you can specify a higher memory limit using the -d flag:**

  ```bash
  caffeinate -i php -d memory_limit=512M import_consortium_jira.php --start=0 --skip-existing
  ```   
--- 
2. **Run the log duplicates script (Optional)**
    If you want to be sure no duplicates have been created in the sync process, you can run the log duplicates script.
    This will get all issues in the target project, and check if there are any other issues mapped to the same Consortium Jira Key.
    If it is a duplicated it will add the number of duplicates, the issue number, and the list of duplicates.

    ```bash
    php log_duplicates.php [options]
    ```

---
## ✅ Available Options
| Option            | Description                                                            |
| ----------------- | ---------------------------------------------------------------------- |
| `--start`         | Start index of issues to sync (inclusive)                              |
| `--end`           | End index of issues to sync (inclusive)                                |
| `--batches`       | Number of batches to process (each batch uses `--batch-size`)          |
| `--batch-size`    | Number of issues to process per batch (default: 10)                    |

---
## 👇 Examples

**Sync a specific range of issues:**

  ```bash
  php log_duplicates.php --start=0 --end=100
  ```
**Sync in batches:**

  ```bash
  php log_duplicates.php --start=0 --batches=5 --batch-size=100
  ```
**Skip existing issues to speed up the sync:**

  ```bash
  php log_duplicates.php --start=0 --skip-existing
  ```  
**You can execute your command directly with caffeinate to ensure the system stays awake for the duration of the process:**

  ```bash
  caffeinate -i php log_duplicates.php --start=0 --skip-existing
  ```  
**If you run into memory allocation errors you can you can specify a higher memory limit using the -d flag:**

  ```bash
  caffeinate -i php -d memory_limit=512M log_duplicates.php --start=0 --skip-existing
  ```  
  ---

## 🤪 Debugging and Testing in Postman

To test your Jira API calls manually using Postman:

### 🧩 1. Import the Postman Collection

1. Download or open the file `Atlassian Jira Endpoints.postman_collection`.
2. Open [Postman](https://www.postman.com/).
3. Click **"Import"** in the top-left corner of the workspace.
4. Choose the `Atlassian Jira Endpoints.postman_collection` file.
5. Click **"Import"** to add the collection to your workspace.

---

### ⚙️ 2. Set Required Collection Variables

Once the collection is imported:

1. Click on the imported collection in the left-hand sidebar.
2. Go to the **"Variables"** tab.
3. Set the following collection-level variables:

| Variable           | Description                       |
|--------------------|-----------------------------------|
| `sourceDomain`     | Domain of the source Jira site    |
| `targetDomain`     | Domain of the target Jira site    |
| `sourceProjectKey` | Key of the source project         |
| `targetProjectKey` | Key of the target project         |
| `username`         | Your Atlassian account email      |

> These variables will be used in URL placeholders and headers throughout the collection.

---

### 🔐 3. Set the API Key via Vault

1. In the top-right corner of Postman, click on your profile icon and go to **"Environment and Vault"**.
2. Under **Vault**, add a new variable:
   - **Key:** `JIRA_API_KEY`
   - **Value:** Your Jira API token
3. All requests will use:
Authorization: Basic {{username}}:{{vault:JIRA_API_KEY}}
or a Base64-encoded equivalent, depending on how the request is configured.

---

### 🚀 4. Test a Request

1. Open a request from the collection (e.g., **Get Issue Metadata**).
2. Make sure the environment and collection variables are filled in.
3. Click **Send** to make the API call.
4. Inspect the response for success or error feedback.

---

## 📖 Documentation:

* [Jira REST API reference](https://developer.atlassian.com/cloud/jira/platform/rest/v3/intro/)
* [Postman documentation](https://learning.postman.com/docs/getting-started/introduction/)

---

## 📋 Sample Response Logs

Jira Sync Log:

```log
[2025-06-08T05:01:46.964326+00:00] jira_sync.ERROR: Error processing issue: ME-10274 PUT request failed: HTTP code 400 {"method":"PUT","url":"https://uottawa.atlassian.net/rest/api/3/issue/ETCD-1581","httpCode":400,"response":{"errorMessages":["INVALID_INPUT"],"errors":[]}} []
[2025-06-08T05:02:04.440504+00:00] jira_sync.WARNING: Skipping media node: missing URL or filename. {"issueKey":"ME-10270","filename":null,"attachmentUrl":null,"mediaId":null,"node":{"type":"media","attrs":{"type":"external","url":"https://wusmed.monday.com/protected_static/1366236/resources/1544913853/big-image.png","height":242,"width":450}}} []
Falling back to text for comment: ETCD-2092 - POST request failed: HTTP code 400 {"method":"POST","url":"https://uottawa.atlassian.net/rest/api/3/issue/ETCD-2092/comment","httpCode":400,"response":{"errorMessages":["INVALID_INPUT"],"errors":[]}}
```

API Error (example):

```log
[2025-06-08T07:14:12.375727+00:00] api_error.ERROR: PUT request failed: HTTP code 400 [] []
[2025-06-08T07:14:12.376759+00:00] api_error.ERROR: https://uottawa.atlassian.net/rest/api/3/issue/ETCD-2012 [] []
[2025-06-08T07:14:12.376883+00:00] api_error.ERROR: {"errorMessages":["INVALID_INPUT"],"errors":{}} [] []
[2025-06-08T07:14:12.376957+00:00] api_error.ERROR: {"fields":{"summary":"ME-8413: Update JQuery to 3.x","customfield_12385":"ME-8413","description":{"type":"doc","version":1,"content":[{"type":"panel","content":[{"type":"paragraph","content":[{"type":"text","text":"Description: Currently we have many components that are using Juqery 1.x but the Jquery library needs to be update with the newer version of JQuery. "}]}],"attrs":{"panelType":"info"}}]}]}]},"customfield_12419":["Javascript-Library-JS"],"customfield_12421":["1.26.0"],"priority":{"name":"Medium"},"customfield_12422":"Graham Berry"}} [] []
```

Find Duplicates Log:
```log
[2025-06-08T04:37:54.375041+00:00] find_duplicates.INFO: 1 duplicates of ETCD-10881 [{"expand":"operations,versionedRepresentations,editmeta,changelog,renderedFields","id":"182609","self":"https://uottawa.atlassian.net/rest/api/3/issue/182609","key":"ETCD-10880","fields":{"customfield_12385":"ME-8013"}}] []
[2025-06-08T04:37:55.775913+00:00] find_duplicates.INFO: 1 duplicates of ETCD-10880 [{"expand":"operations,versionedRepresentations,editmeta,changelog,renderedFields","id":"182610","self":"https://uottawa.atlassian.net/rest/api/3/issue/182610","key":"ETCD-10881","fields":{"customfield_12385":"ME-8013"}}] []
```


---

## ⚠️ Pain Points & Troubleshooting

### 🧩 Issue Type Mismatches & Relationships

- The **source project** (e.g., `ME`) often uses **non-standard issue types** or **custom configurations** (like “Epic,” “Story,” “Sub-task,” or renamed types).
- These types may not exist in the **target project**, causing 400-level errors on creation.
- To resolve this:
  - Use the `.env` file to **map or override issue types** explicitly.
  - Ensure any parent-child or epic links are valid for both source and target schemas.
  - Sub-task creation may require setting `parent` instead of using `issueLink`.

### 🧷 Parent/Child & Epic Links

- Relationships like "is parent of" or "is epic of" were failing when issue types didn’t support them.
- Validate the target project allows sub-tasks and epics.
- Use the `editmeta` API to confirm allowable fields/relations for the issue type.

### 🪵 ADF (Atlassian Document Format) Issues

- Major challenges were encountered sanitizing and submitting ADF (used in `description` and `comment` fields).
  - Nested structures sometimes exceeded Jira's parser limit.
  - Empty `attrs` fields (e.g., `[]`) in tables caused encoding failures. These had to be removed or replaced with `{}` (e.g., using `unset()` or casting to `new \stdClass()`).
  - Some rich text failed silently or caused partial syncs.
- Recommendation: use the `sanitizeADF()` and `flattenADF()` utilities, and log any failing payloads for later manual cleanup.

### 🧼 Inconsistent JSON Encoding

- Some fields (like attachments or deeply nested lists) broke due to PHP’s `json_encode()` producing arrays where Jira expects objects.
- Use `JSON_PRESERVE_ZERO_FRACTION` and validate your structure via Postman or curl before syncing at scale.

### ⏩ Skipping Already Synced Issues

- Use `--skip-existing` to avoid re-processing issues that already exist in the target.
- This is especially useful for testing or resuming interrupted syncs.

### 🧪 Hard-to-Detect Failures

- Errors like “Field cannot be set” may mean:
  - The field is not on the screen.
  - The field value is invalid in the target project context.
- Always check the `editmeta` endpoint (`GET /rest/api/3/issue/{issueKey}/editmeta`) to validate available fields for that issue type.

### 🧪 Troubleshooting tips

- Errors and warnings will be logged in logs/jira_synch.log
  - Falling back to text for comment: means the comment body couldn't be resolved with RTF so it will try to contain as much of the meaning in text format.
  - Skipping media node means, the original description/comment body had a file/media block in it, but no filename. The file couldn't be uploaded to link to.
  - Error processing issue means the issue was not updated/created in some way. Check the api_error log for more details.
-  The api_error.log will post the full url, method, response and payload of any failed PUT/POST methods
   - Copy the full payload and url to test in Postman
   - Click the Beautify button to put it in a more readable format and look for any possible errors that do not match the Atlassian Document Format (see below for documentation on syntax)
   - Try to minimize the payload until you target the issue
   - If possible, make an update to the code to handle any missed sanitization
   - Alternatively you can just make a list of the erroneaous issues and create them manually in your target project. 

* Jira API Reference: [Jira Cloud Platform REST API](https://developer.atlassian.com/cloud/jira/platform/rest/v3/intro/)
* Atlassian Document Format: [Atlassian Document Format](https://developer.atlassian.com/cloud/jira/platform/apis/document/structure/)

---

## ✅ Checklist

* [ ] API token created and added to env.
* [ ] Target project custom fields created.
* [ ] Workflow and screen configurations match source project.
* [ ] `$customFields` mapping updated in env.

---

## 📾 License

This script is provided "as is" under the MIT License. Feel free to modify and distribute.

---

