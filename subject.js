let currentSubject = null;

function editSubject(id){

    currentSubject = id;

    fetch("api/subject.php?action=get")

    .then(response => response.json())

    .then(result=>{

        const subject = result.data.find(

            s=>s.subject_id==id

        );

        document.getElementById(
            "editSubjectId"
        ).value=subject.subject_id;

        document.getElementById(
            "editName"
        ).value=subject.subject_name;

        document.getElementById(
            "editDescription"
        ).value=subject.description ?? "";

        openModal("editSubjectModal");



    });

}

async function saveSubjectChanges(){

    const formData = new FormData();

    formData.append("action","update");

    formData.append(
        "subject_id",
        currentSubject
    );

    formData.append(
        "subject_name",
        document.getElementById(
            "editName"
        ).value
    );

    formData.append(
        "description",
        document.getElementById(
            "editDescription"
        ).value
    );

    const response = await fetch("api/subject.php",

        {

            method:"POST",

            body:formData

        }

    );

    const result = await response.json();

    showFlashMessage(
        result.message,
        result.success ? "success" : "error"
    );

    if(result.success){

        closeModal("editSubjectModal");

        loadSubjects();

    }

}


async function loadSubjects() {

    const response = await fetch("api/subject.php?action=get");

    const result = await response.json();

    if (!result.success) {

        showFlashMessage(
            result.message,
            result.success ? "success" : "error"
        );

        return;

    }

    const tbody = document.getElementById("subjectTableBody");

    tbody.innerHTML = "";

    result.data.forEach(subject => {

        tbody.innerHTML += `
            <tr>

                <td>${subject.subject_id}</td>

                <td>${subject.subject_name}</td>

                <td>${subject.description ?? ""}</td>

                <td>

                    <button class="action-btn edit-btn"
                        onclick="editSubject(${subject.subject_id})">

                        <i class="fa-solid fa-pen"></i>

                    </button>

                    <button class="action-btn delete-btn"
                        onclick="deleteSubject(${subject.subject_id})">

                        <i class="fa-solid fa-trash"></i>

                    </button>

                </td>

            </tr>
        `;

    });

    document.getElementById("totalSubjects").innerText = result.data.length;

    document.getElementById("countInfo").innerText =
        `Showing ${result.data.length} result(s)`;

}

loadSubjects();

async function createSubject() {

    const subjectName =
        document.getElementById("newSubjectName").value.trim();

    const description =
        document.getElementById("newDescription").value.trim();

    if (subjectName === "") {

        showFlashMessage("Subject name is required.","error");

        return;

    }

    const formData = new FormData();
    
    formData.append("action","add");
    formData.append("subject_name", subjectName);
    formData.append("description", description);

    const response = await fetch("api/subject.php",
        {
            method: "POST",
            body: formData
        }
    );

    const result = await response.json();

    showFlashMessage(
        result.message,
        result.success ? "success" : "error"
    );

    if (result.success) {

        closeModal("addSubjectModal");

        document.getElementById("newSubjectName").value = "";
        document.getElementById("newDescription").value = "";

        loadSubjects();

    }

}

async function deleteSubject(id){

    const confirmDelete = confirm(
        "Are you sure you want to delete this subject?"
    );

    if(!confirmDelete){

        return;

    }

    const formData = new FormData();

    formData.append("action","delete");

    formData.append("subject_id", id);

    const response = await fetch("api/subject.php",
        {

            method:"POST",

            body:formData

        }

    );

    const result = await response.json();

    showFlashMessage(
        result.message,
        result.success ? "success" : "error"
    );

    if(result.success){

        loadSubjects();

    }

}