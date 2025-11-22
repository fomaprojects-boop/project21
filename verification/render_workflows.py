
# 1. Read the index.php to extract head content
with open('index.php', 'r') as f:
    index_content = f.read()

# Extract head
head_start = index_content.find('<head>')
head_end = index_content.find('</head>') + 7
head_content = index_content[head_start:head_end]

# Clean PHP from head
import re
head_content = re.sub(r'<\?php.*?\?>', '', head_content, flags=re.DOTALL)

# 2. Define the new workflows template (copy from previous step)
workflows_html = r"""<div class="h-full flex flex-col">
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
                <button class="bg-white text-violet-600 px-6 py-3 rounded-xl hover:bg-violet-50 font-bold shadow-md transition-transform hover:scale-105 flex items-center">
                    <i class="fas fa-plus-circle mr-2 text-xl"></i> Create Workflow
                </button>
            </div>
        </div>

        <!-- Your Workflows Section -->
        <div class="flex justify-between items-center mb-6">
             <h3 class="text-xl font-bold text-gray-800 flex items-center"><i class="fas fa-project-diagram text-violet-500 mr-3"></i>Your Workflows</h3>
        </div>
        <div id="workflows-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
            <!-- Mock Workflow Card -->
            <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 hover:shadow-md transition-all group relative overflow-hidden">
                <div class="absolute top-0 left-0 w-1 h-full bg-violet-500"></div>
                <div class="flex justify-between items-start mb-3">
                    <h4 class="font-bold text-lg text-gray-800 group-hover:text-violet-600 transition-colors truncate pr-2">Welcome Message</h4>
                    <span class="bg-green-100 text-green-700 text-xs font-bold px-2 py-1 rounded uppercase tracking-wide flex-shrink-0">Active</span>
                </div>
                <p class="text-sm text-gray-500 mb-6 flex items-center bg-gray-50 p-2 rounded-lg">
                    <i class="fas fa-bolt text-amber-500 mr-2"></i>
                    <span class="truncate">Trigger: <span class="font-medium text-gray-700">Conversation Started</span></span>
                </p>
                <div class="flex gap-3 mt-auto">
                    <button class="flex-1 bg-white border border-gray-200 text-gray-700 px-3 py-2 rounded-lg font-semibold hover:bg-violet-50 hover:text-violet-600 hover:border-violet-200 transition-colors text-sm flex items-center justify-center">
                        <i class="fas fa-edit mr-2"></i> Edit
                    </button>
                    <button class="flex-none bg-white border border-gray-200 text-gray-400 px-3 py-2 rounded-lg hover:bg-red-50 hover:text-red-500 hover:border-red-200 transition-colors" title="Delete">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Templates Section -->
        <div class="border-t pt-10">
            <div class="flex flex-col mb-6">
                <h3 class="text-xl font-bold text-gray-800 flex items-center"><i class="fas fa-magic text-amber-500 mr-3"></i>Start with a Template</h3>
                <p class="text-gray-500 ml-8 text-sm">Pre-built workflows optimized for conversion and support.</p>
            </div>
            <div id="workflow-templates-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Mock Template Card -->
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 hover:border-violet-200 hover:shadow-lg transition-all flex flex-col h-full relative group">
                    <div class="mb-4">
                         <div class="w-12 h-12 rounded-lg bg-violet-100 text-violet-600 flex items-center justify-center mb-4 group-hover:scale-110 transition-transform duration-300">
                            <i class="fas fa-file-alt text-xl"></i>
                        </div>
                        <h4 class="font-bold text-lg text-gray-800 mb-2 group-hover:text-violet-600 transition-colors">Lead Qualification</h4>
                        <p class="text-sm text-gray-500 leading-relaxed line-clamp-3">Automatically qualify leads by asking key questions before routing them to an agent.</p>
                    </div>
                    <div class="mt-auto pt-4">
                        <button class="w-full bg-white border-2 border-violet-100 text-violet-600 font-bold py-2.5 rounded-lg hover:bg-violet-600 hover:text-white hover:border-violet-600 transition-all flex justify-center items-center group-hover/btn:shadow-md">
                            <span>Use Template</span> <i class="fas fa-arrow-right ml-2 transform group-hover:translate-x-1 transition-transform"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>"""

full_html = f"""<!DOCTYPE html>
<html lang="en">
{head_content}
<body class="bg-gray-100 h-screen flex flex-col">
    <div class="flex flex-1 w-full text-white overflow-hidden">
        <main class="flex-1 flex flex-col bg-gray-50 text-gray-800 min-w-0">
            <div id="view-container" class="flex-1 overflow-y-auto">
                {workflows_html}
            </div>
        </main>
    </div>
</body>
</html>"""

with open('verification/workflows_view.html', 'w') as f:
    f.write(full_html)

print("Created verification/workflows_view.html")
