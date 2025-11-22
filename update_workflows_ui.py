import re

# 1. Read the file
with open('index.php', 'r') as f:
    content = f.read()

# 2. Define the new workflows template
new_workflows_template = r"""workflows: `<div class="h-full flex flex-col">
    <div id="workflow-main-view" class="p-8 h-full overflow-y-auto">
        <!-- Hero Section -->
        <div class="bg-gradient-to-r from-violet-600 to-indigo-600 rounded-2xl p-8 text-white mb-10 shadow-lg relative overflow-hidden">
            <div class="absolute top-0 right-0 -mt-10 -mr-10 w-64 h-64 bg-white opacity-10 rounded-full blur-3xl"></div>
            <div class="absolute bottom-0 left-0 -mb-10 -ml-10 w-40 h-40 bg-purple-400 opacity-10 rounded-full blur-2xl"></div>
            <div class="flex flex-col md:flex-row justify-between items-center relative z-10">
                <div class="mb-6 md:mb-0">
                    <h2 class="text-3xl font-bold mb-2">Automate Your Business</h2>
                    <p class="text-violet-100 text-lg max-w-xl">Create powerful workflows to handle conversations, sales, and support automatically. Save time and grow faster.</p>
                </div>
                <button onclick="openWorkflowEditor()" class="bg-white text-violet-600 px-6 py-3 rounded-xl hover:bg-violet-50 font-bold shadow-md transition-transform hover:scale-105 flex items-center">
                    <i class="fas fa-plus-circle mr-2 text-xl"></i> Create Workflow
                </button>
            </div>
        </div>

        <!-- Your Workflows Section -->
        <div class="flex justify-between items-center mb-6">
             <h3 class="text-xl font-bold text-gray-800 flex items-center"><i class="fas fa-project-diagram text-violet-500 mr-3"></i>Your Workflows</h3>
        </div>
        <div id="workflows-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
            <!-- Workflows will be loaded here -->
            <div class="col-span-full py-12 text-center text-gray-500">
                 <i class="fas fa-spinner fa-spin text-2xl mb-3"></i>
                 <p>Loading workflows...</p>
            </div>
        </div>

        <!-- Templates Section -->
        <div class="border-t pt-10">
            <div class="flex flex-col mb-6">
                <h3 class="text-xl font-bold text-gray-800 flex items-center"><i class="fas fa-magic text-amber-500 mr-3"></i>Start with a Template</h3>
                <p class="text-gray-500 ml-8 text-sm">Pre-built workflows optimized for conversion and support.</p>
            </div>
            <div id="workflow-templates-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Templates will be loaded here -->
                <div class="col-span-full py-12 text-center text-gray-500">
                     <i class="fas fa-spinner fa-spin text-2xl mb-3"></i>
                     <p>Loading templates...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Workflow Editor View (Hidden by default) -->
    <div id="workflow-editor-view" class="hidden bg-gray-50 h-full flex flex-col relative workflow-canvas-bg">
        <!-- Editor Header -->
        <div class="flex justify-between items-center px-6 py-3 bg-white border-b shadow-sm z-20">
            <div class="flex items-center gap-4">
                <button onclick="closeWorkflowEditor()" class="text-gray-400 hover:text-gray-700 transition-colors p-2 rounded-full hover:bg-gray-100">
                    <i class="fas fa-arrow-left text-lg"></i>
                </button>
                <div class="flex flex-col">
                    <label class="text-xs text-gray-400 font-semibold uppercase tracking-wider">Workflow Name</label>
                    <input type="text" id="workflow-name-input" placeholder="Untitled Workflow" class="text-lg font-bold text-gray-800 bg-transparent border-none focus:ring-0 p-0 placeholder-gray-300">
                </div>
            </div>
            <div class="flex gap-3">
                <button onclick="exportWorkflowJSON()" class="text-gray-500 hover:text-violet-600 bg-gray-100 hover:bg-violet-50 px-4 py-2 rounded-lg text-sm font-semibold transition-colors" title="Export to JSON">
                    <i class="fas fa-download mr-2"></i>Export
                </button>
                <button onclick="importWorkflowJSON()" class="text-gray-500 hover:text-violet-600 bg-gray-100 hover:bg-violet-50 px-4 py-2 rounded-lg text-sm font-semibold transition-colors" title="Import from JSON">
                    <i class="fas fa-upload mr-2"></i>Import
                </button>
                <button onclick="saveWorkflow()" class="bg-violet-600 text-white px-6 py-2 rounded-lg hover:bg-violet-700 font-semibold shadow-sm transition-all hover:shadow-md flex items-center">
                    <i class="fas fa-save mr-2"></i>Save
                </button>
            </div>
        </div>

        <!-- Editor Canvas -->
        <div class="workflow-canvas flex-1 overflow-auto relative p-10">
            <div id="workflow-editor-canvas" class="min-w-full min-h-full flex justify-center items-start pt-10 pb-40"></div>
        </div>
    </div>
</div>`,"""

# Replace workflows template
# Using regex to find the existing workflows key in viewTemplates object
# We look for workflows: `...`, followed by reports:
content = re.sub(r'workflows:\s*`.*?`,\s*reports:', new_workflows_template + ' reports:', content, flags=re.DOTALL)


# 3. Update loadWorkflows function
# We will construct a replacement that looks for the exact existing function body if possible,
# or use regex to replace the function definition.
# Original:
# async function loadWorkflows() {
#            const list = document.getElementById('workflows-list');
#            list.innerHTML = `<p class="text-gray-500 col-span-4">Loading...</p>`;
#            const data = await fetchApi('get_workflows.php');
#            const workflows = (data && Array.isArray(data)) ? data : [];
#            list.innerHTML = workflows.map(w => `<div class="bg-white p-5 rounded-lg shadow border"><div class="flex justify-between items-center"><h4 class="font-bold text-violet-700">${w.name}</h4></div><p class="text-sm text-gray-500 mt-2">Trigger: ${w.trigger_type}</p><div class="mt-4 flex gap-2"><button onclick="openWorkflowEditor(${w.id})" class="text-sm bg-violet-100 text-violet-700 px-3 py-1 rounded-full font-semibold">Edit</button><button onclick="deleteWorkflow(${w.id}, '${w.name.replace(/'/g, "\\'")}')" class="text-sm bg-red-100 text-red-700 px-3 py-1 rounded-full font-semibold">Delete</button></div></div>`).join('');
#            if (workflows.length === 0) list.innerHTML = `<p class="text-gray-500 col-span-4">You haven't created any workflows yet.</p>`;
#        }

new_loadWorkflows_content = r"""async function loadWorkflows() {
            const list = document.getElementById('workflows-list');
            if (!list) return;

            const data = await fetchApi('get_workflows.php');
            const workflows = (data && Array.isArray(data)) ? data : [];

            if (workflows.length === 0) {
                list.innerHTML = `<div class="col-span-full flex flex-col items-center justify-center py-12 bg-gray-50 rounded-xl border border-dashed border-gray-300">
                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-4 text-gray-400">
                        <i class="fas fa-wind text-3xl"></i>
                    </div>
                    <h4 class="text-lg font-semibold text-gray-600">No Workflows Yet</h4>
                    <p class="text-gray-500 mb-6 text-sm">Create your first automated workflow to get started.</p>
                    <button onclick="openWorkflowEditor()" class="bg-violet-600 text-white px-5 py-2 rounded-lg hover:bg-violet-700 font-semibold text-sm">Create Workflow</button>
                </div>`;
                return;
            }

            list.innerHTML = workflows.map(w => `
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 hover:shadow-md transition-all group relative overflow-hidden">
                    <div class="absolute top-0 left-0 w-1 h-full bg-violet-500"></div>
                    <div class="flex justify-between items-start mb-3">
                        <h4 class="font-bold text-lg text-gray-800 group-hover:text-violet-600 transition-colors truncate pr-2">${w.name}</h4>
                        <span class="bg-green-100 text-green-700 text-xs font-bold px-2 py-1 rounded uppercase tracking-wide flex-shrink-0">Active</span>
                    </div>
                    <p class="text-sm text-gray-500 mb-6 flex items-center bg-gray-50 p-2 rounded-lg">
                        <i class="fas fa-bolt text-amber-500 mr-2"></i>
                        <span class="truncate">Trigger: <span class="font-medium text-gray-700">${w.trigger_type}</span></span>
                    </p>
                    <div class="flex gap-3 mt-auto">
                        <button onclick="openWorkflowEditor(${w.id})" class="flex-1 bg-white border border-gray-200 text-gray-700 px-3 py-2 rounded-lg font-semibold hover:bg-violet-50 hover:text-violet-600 hover:border-violet-200 transition-colors text-sm flex items-center justify-center">
                            <i class="fas fa-edit mr-2"></i> Edit
                        </button>
                        <button onclick="deleteWorkflow(${w.id}, '${w.name.replace(/'/g, "\\'")}')" class="flex-none bg-white border border-gray-200 text-gray-400 px-3 py-2 rounded-lg hover:bg-red-50 hover:text-red-500 hover:border-red-200 transition-colors" title="Delete">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
            `).join('');
        }"""

# Using regex to capture the whole function block.
# We assume the function ends with a closing brace on a new line or at the end of the block.
# Since JS functions can have nested braces, regex is risky without recursion.
# But we know the exact string of the OLD function from reading the file.
old_loadWorkflows_signature = r"async function loadWorkflows\(\) \{.*?\}"
# This is tricky because `.` doesn't match newlines by default, but we can use re.DOTALL.
# However, we need to match until the *correct* closing brace.
# Instead, let's look for the start of the function and the start of the NEXT function `let loadedWorkflowTemplates = [];`

# Find start index
start_marker = "async function loadWorkflows() {"
end_marker = "let loadedWorkflowTemplates = [];"

start_idx = content.find(start_marker)
end_idx = content.find(end_marker)

if start_idx != -1 and end_idx != -1:
    # Replace the chunk between them
    content = content[:start_idx] + new_loadWorkflows_content + "\n        " + content[end_idx:]
else:
    print("Could not find loadWorkflows function block via markers.")


# 4. Update loadWorkflowTemplates function
# Original:
# async function loadWorkflowTemplates() {
#            const grid = document.getElementById('workflow-templates-grid');
#            grid.innerHTML = `<p class="text-gray-500 col-span-4">Loading...</p>`;
#            const templates = await fetchApi('get_workflow_templates.php');
#            loadedWorkflowTemplates = (templates && Array.isArray(templates)) ? templates : [];
#            grid.innerHTML = loadedWorkflowTemplates.map(t => `<div class="bg-white p-5 rounded-lg shadow border flex flex-col"><h4 class="font-bold">${t.title}</h4><p class="text-sm text-gray-500 mt-1 flex-grow">${t.description}</p><button onclick="useWorkflowTemplateById(${t.id})" class="mt-4 w-full bg-violet-50 text-violet-700 font-semibold py-2 rounded-lg hover:bg-violet-100">Use Template</button></div>`).join('');
#        }

new_loadWorkflowTemplates_content = r"""async function loadWorkflowTemplates() {
            const grid = document.getElementById('workflow-templates-grid');
            if (!grid) return;

            const templates = await fetchApi('get_workflow_templates.php');
            loadedWorkflowTemplates = (templates && Array.isArray(templates)) ? templates : [];

            if (loadedWorkflowTemplates.length === 0) {
                grid.innerHTML = `<p class="text-gray-500 col-span-full text-center py-8">No templates available at the moment.</p>`;
                return;
            }

            grid.innerHTML = loadedWorkflowTemplates.map(t => `
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 hover:border-violet-200 hover:shadow-lg transition-all flex flex-col h-full relative group">
                    <div class="mb-4">
                         <div class="w-12 h-12 rounded-lg bg-violet-100 text-violet-600 flex items-center justify-center mb-4 group-hover:scale-110 transition-transform duration-300">
                            <i class="fas fa-file-alt text-xl"></i>
                        </div>
                        <h4 class="font-bold text-lg text-gray-800 mb-2 group-hover:text-violet-600 transition-colors">${t.title}</h4>
                        <p class="text-sm text-gray-500 leading-relaxed line-clamp-3">${t.description}</p>
                    </div>
                    <div class="mt-auto pt-4">
                        <button onclick="useWorkflowTemplateById(${t.id})" class="w-full bg-white border-2 border-violet-100 text-violet-600 font-bold py-2.5 rounded-lg hover:bg-violet-600 hover:text-white hover:border-violet-600 transition-all flex justify-center items-center group-hover/btn:shadow-md">
                            <span>Use Template</span> <i class="fas fa-arrow-right ml-2 transform group-hover:translate-x-1 transition-transform"></i>
                        </button>
                    </div>
                </div>
            `).join('');
        }"""

# Find start index for loadWorkflowTemplates
# It starts after `let loadedWorkflowTemplates = [];`
# and ends before `function useWorkflowTemplateById(templateId) {`

start_marker_templates = "async function loadWorkflowTemplates() {"
end_marker_templates = "function useWorkflowTemplateById(templateId) {"

start_idx_t = content.find(start_marker_templates)
end_idx_t = content.find(end_marker_templates)

if start_idx_t != -1 and end_idx_t != -1:
    # Replace the chunk
    content = content[:start_idx_t] + new_loadWorkflowTemplates_content + "\n        " + content[end_idx_t:]
else:
    print("Could not find loadWorkflowTemplates function block via markers.")

with open('index.php', 'w') as f:
    f.write(content)
