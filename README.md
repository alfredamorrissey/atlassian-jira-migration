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
git clone 
composer install
```
---
## 🔐 Creating Your API Key

To authenticate with the Jira REST API, you’ll need to create a personal API token:

1. Go to [https://id.atlassian.com/manage/api-tokens](https://id.atlassian.com/manage/api-tokens).
2. Click **Create API token**.
3. Name your token (e.g., "Jira Sync Script") and click **Create**.
4. Copy the generated token. This is your only chance to view it.
5. In your script, use the token with your email address as Basic Auth:

```php
$headers = [
    'Authorization: Basic ' . base64_encode("you@example.com:your_api_token"),
    'Content-Type: application/json'
];
```

**Note:** Do not hard-code your token into version-controlled scripts. Use environment variables or configuration files excluded from git.

For documentation, see: [Atlassian API tokens](https://support.atlassian.com/atlassian-account/docs/manage-api-tokens-for-your-atlassian-account/)

---

## 🛠 Required Setup in Target Project

### 1. 🧹 Create Custom Fields

You must manually create the following custom fields in your **target Jira project**:

#### a. **Consortium Jira ID**

* **Type**: Text Field (single line)
* **Used for**: Storing the source issue key for reference.

#### b. **Components**

* **Type**: Labels
* **Used for**: Storing the names of the original components when the real `components` field is not available.

Once created, note down their **custom field IDs** (e.g., `customfield_12345`). You can find them by hitting the editmeta endpoint or using browser inspection tools.
https://{yourDomain}.atlassian.net/rest/api/3/issue/{issueKey}/editmeta

Update the .env file with your custom field values:

```php
CF_CONSORTIUM_JIRA_ISSUE=12385
CF_COMPONENTS=12419
CF_FIX_VERSION=12421
CF_REPORTER_NAME=12422
```

---

### 2. 🔄 Set Up Workflow (Optional but Recommended)

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

#### Example Transitions:

* New Issue → In Progress
* In Progress → In Review
* In Review → In Testing
* In Testing → Ready to Merge
* Ready to Merge → Merged
* Any → Available / Done / Closed

**New Project Workflow Reference:**

![New Project Workflow](our_workflow.png)

Note: In the new project, I allowed any status, to go to any status so that the script can move each issue to status of the original issue.

Note: Jira does not allow copying workflows directly via the API. You’ll need to manually replicate the workflow configuration or use Jira’s **shared workflow scheme** if applicable.

Documentation: [Jira Workflow Documentation](https://support.atlassian.com/jira-cloud-administration/docs/manage-your-workflows-in-jira-cloud/)

---

## 📄 How to Use

1. Create your .env from .env.example
2. Populate your sourceDomain, targetDomain, sourceProject, targetProject and Custom Fields variables with the appropriate field IDs.
3. Generate your [Jira API token](https://id.atlassian.com/manage/api-tokens) and set it in your .env.
4. Run the script from CLI:

```bash
php import_consortium_jira.php
```

---

## 💡 Usage Examples

### Example: Create a Single Issue

```php
$issue = [
  'fields' => [
    'summary' => 'Example issue',
    'description' => 'Created via script.',
    'issuetype' => ['name' => 'Task'],
    'components' => [['name' => 'Backend']],
    'priority' => ['name' => 'High']
  ],
  'key' => 'SRC-123'
];

$customFields = [
  'Consortium Jira ID' => '12345',
  'Components' => '12346',
];

$headers = [
  'Authorization: Basic ' . base64_encode("your_email:your_token"),
  'Content-Type: application/json'
];

createIssue($issue, 'your-domain.atlassian.net', 'TARGET', $customFields, $headers);
```

### Example: Update an Existing Issue

```php
updateIssue('TARGET-456', $issue, 'your-domain.atlassian.net', 'TARGET', $customFields, $headers);
```

---

## 🤪 Debugging and Testing in Postman

To test your Jira API calls manually:

1. Open [Postman](https://www.postman.com/).
2. Create a new request with method `GET` or `POST`.
3. Set the URL to your Jira REST endpoint, e.g.:

   * `https://your-domain.atlassian.net/rest/api/3/issue/ETCD-1/editmeta`
4. Under **Authorization**, choose `Basic Auth` and enter your email and API token.
5. Under **Headers**, set:

   * `Content-Type`: `application/json`
6. Add your JSON payload in the **Body** tab using `raw` format.
7. Click **Send** to test.

You can inspect responses and refine your request or payload format based on Jira's feedback.

Documentation:

* [Jira REST API reference](https://developer.atlassian.com/cloud/jira/platform/rest/v3/intro/)
* [Postman documentation](https://learning.postman.com/docs/getting-started/introduction/)

---

## 📋 Sample Response Logs

Successful creation:

```json
Created issue: ETCD-101
```

Failure (example):

```json
Failed to create issue. HTTP Code: 400
Response: {"errors":{"components":"Field 'components' cannot be set. It is not on the appropriate screen, or unknown."}}
```

---

## 🤪 Troubleshooting

* If you see an error related to a field (e.g., `Field 'priority' cannot be set`), check that the field is:

  * Added to the screen in the target project.
  * Has matching options (for fields like priority or status).

* To discover custom field IDs or schema:

  * Use: `GET /rest/api/3/issue/{issueKey}/editmeta`

* Jira API Reference: [Jira Cloud Platform REST API](https://developer.atlassian.com/cloud/jira/platform/rest/v3/intro/)

---

## ✅ Checklist

* [ ] API token created and added to headers.
* [ ] Target project custom fields created.
* [ ] Workflow and screen configurations match source project.
* [ ] `$customFields` mapping updated.

---

## 📾 License

This script is provided "as is" under the MIT License. Feel free to modify and distribute.

---

If you need help setting up the script or customizing it further, feel free to reach out to the maintainer.
