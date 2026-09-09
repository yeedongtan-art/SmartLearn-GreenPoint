let allLogs = [];

async function loadLogs(){

    const response = await fetch(

        "api/activity_logs.php?action=getLogs"

    );

    const result = await response.json();

    if(!result.success){

        showFlashMessage(
            result.message,
            result.success ? "success" : "error"
        );

        return;

    }

    allLogs = result.data;

    renderLogs(allLogs);

}

function renderLogs(data){

    const tableBody =

    document.getElementById("logsTableBody");

    tableBody.innerHTML = "";

    data.forEach(log=>{

        tableBody.innerHTML += `

        <tr>

            <td>

                ${log.activity_date}

            </td>

            <td>

                ${log.name}

            </td>

            <td>

                ${log.activity_description}

            </td>

            <td>

                ${log.activity_type}

            </td>

            <td>

                <span class="badge ${

                    log.log_status=="Success"

                    ? "active-status"

                    : "warning-status"

                }">

                    ${log.log_status}

                </span>

            </td>

        </tr>

        `;

    });

    document.getElementById("logsCountInfo").innerText =

    `Showing ${data.length} log(s)`;

}

function searchLogs(){

    const keyword =

    document

    .getElementById("logSearchInput")

    .value

    .toLowerCase();

    const filteredLogs = allLogs.filter(log=>{

        return(

            log.name

            .toLowerCase()

            .includes(keyword)

            ||

            log.activity_description

            .toLowerCase()

            .includes(keyword)

        );

    });

    renderLogs(filteredLogs);

}


function exportLogs(){

    const keyword =

    document

    .getElementById("logSearchInput")

    .value

    .toLowerCase();

    const exportData = allLogs.filter(log=>{

        return(

            log.name

            .toLowerCase()

            .includes(keyword)

            ||

            log.activity_description

            .toLowerCase()

            .includes(keyword)

        );

    });

    let csv =

    "Date,User,Description,Module,Status\n";

    exportData.forEach(log=>{

        csv +=

        `"${log.activity_date}","${log.name}","${log.activity_description}","${log.activity_type}","${log.log_status}"\n`;

    });

    const blob = new Blob(

        [csv],

        {

            type:"text/csv"

        }

    );

    const url =

    URL.createObjectURL(blob);

    const link =

    document.createElement("a");

    link.href = url;

    link.download = "activity_logs.csv";

    link.click();

    URL.revokeObjectURL(url);

}

loadLogs();