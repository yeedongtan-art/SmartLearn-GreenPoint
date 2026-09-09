let currentAssignment = null;

async function loadTeachers(){

    try {

    const response =
    await fetch(
        "api/teacher_assignment.php?action=getTeachers"
    );

    const result =
    await response.json();

    const teacherSelect =
    document.getElementById("teacherSelect");

    teacherSelect.innerHTML =
    '<option value="">Choose Teacher</option>';

    result.data.forEach(teacher=>{

        teacherSelect.innerHTML += `

            <option value="${teacher.user_id}">

                ${teacher.name}

            </option>

        `;

    });

    } catch (error) {

        showFlashMessage("Unable to load teachers.", "error");

    }

}

async function loadSubjects(){

    try {

    const response =
    await fetch(
        "api/teacher_assignment.php?action=getSubjects"
    );

    const result =
    await response.json();

    const subjectSelect =
    document.getElementById("subjectSelect");

    subjectSelect.innerHTML =
    '<option value="">Choose Subject</option>';

    result.data.forEach(subject=>{

        subjectSelect.innerHTML += `

            <option value="${subject.subject_id}">

                ${subject.subject_name}

            </option>

        `;

    });

    } catch (error) {

        showFlashMessage("Unable to load subjects.", "error");

    }

}

async function loadClasses(){

    try {

    const response =
    await fetch(
        "api/teacher_assignment.php?action=getClasses"
    );

    const result =
    await response.json();

    const classSelect =
    document.getElementById("classSelect");

    classSelect.innerHTML =
    '<option value="">Choose Class</option>';

    result.data.forEach(classroom=>{

        classSelect.innerHTML += `

            <option value="${classroom.class_id}">

                ${classroom.class_name}

            </option>

        `;

    });

    } catch (error) {

        showFlashMessage("Unable to load classes.", "error");

    }

}

async function loadAssignments(){

    try {

    const response = await fetch(
        "api/teacher_assignment.php?action=getAssignments"
    );

    const result = await response.json();

    if(!result.success){

        showFlashMessage(
            result.message,
            result.success ? "success" : "error"
        );

        return;

    }

    const tbody =
    document.getElementById("assignmentTableBody");

    tbody.innerHTML="";

    result.data.forEach(assignment=>{

        tbody.innerHTML += `

        <tr>

            <td>${assignment.teacher_name}</td>

            <td>${assignment.subject_name}</td>

            <td>${assignment.class_name}</td>

            <td>${assignment.status}</td>

            <td>

                <div class="actions">

                    <button
                    class="action-btn"
                    onclick="editAssignment(${assignment.classes_id})">

                        <i class="fa-solid fa-pen"></i>

                    </button>

                    <button
                    class="action-btn"
                    onclick="deleteAssignment(${assignment.classes_id})">

                        <i class="fa-solid fa-trash"></i>

                    </button>

                </div>

            </td>

        </tr>

        `;

    });

    document.getElementById("assignmentCountInfo").innerText =
    `Showing ${result.data.length} assignments`;

    } catch (error) {

        showFlashMessage("Unable to load assignments.", "error");

    }

}

async function createAssignment(){

    try {

    const teacher =
    document.getElementById("teacherSelect").value;

    const subject =
    document.getElementById("subjectSelect").value;

    const classroom =
    document.getElementById("classSelect").value;

    if(
        teacher==="" ||
        subject==="" ||
        classroom===""){
        showFlashMessage("Please select all fields.","error");
        return;
    }

    const formData = new FormData();

    formData.append("action","add");

    formData.append("user_id",teacher);

    formData.append("subject_id",subject);

    formData.append("class_id",classroom);

    const response = await fetch(
        "api/teacher_assignment.php",
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

        document.getElementById("teacherSelect").selectedIndex = 0;
        document.getElementById("subjectSelect").selectedIndex = 0;
        document.getElementById("classSelect").selectedIndex = 0;

        loadAssignments();

    }

    } catch (error) {

        showFlashMessage("Unable to create assignment.", "error");

    }

}

async function deleteAssignment(id){

    try {

    if(!confirm(
        "Are you sure you want to delete this assignment?"
    )){

        return;

    }

    const formData = new FormData();

    formData.append("action","delete");
    formData.append("classes_id",id);

    const response = await fetch(
        "api/teacher_assignment.php",
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

        loadAssignments();

    }

    } catch (error) {

        showFlashMessage("Unable to delete assignment.", "error");

    }

}

async function editAssignment(id){

    try {

    currentAssignment = id;

    const response = await fetch(
        "api/teacher_assignment.php?action=getAssignments"
    );

    const result = await response.json();

    const assignment = result.data.find(
        a=>a.classes_id==id
    );

    await loadEditTeachers(assignment.user_id);

    await loadEditSubjects(assignment.subject_id);

    await loadEditClasses(assignment.class_id);

    document.getElementById("editStatus").value =
    assignment.status;

    openModal("editAssignmentModal");

    } catch (error) {

        showFlashMessage("Unable to load assignment details.", "error");

    }

}

async function saveAssignmentChanges(){

    try {

    const formData=new FormData();

    formData.append("action","update");

    formData.append(
        "classes_id",
        currentAssignment
    );

    formData.append(
        "user_id",
        document.getElementById("editTeacher").value
    );

    formData.append(
        "subject_id",
        document.getElementById("editSubject").value
    );

    formData.append(
        "class_id",
        document.getElementById("editClass").value
    );

    formData.append(
        "status",
        document.getElementById("editStatus").value
    );

    const response=await fetch(

        "api/teacher_assignment.php",

        {

            method:"POST",

            body:formData

        }

    );

    const result=await response.json();

    showFlashMessage(
        result.message,
        result.success ? "success" : "error"
    );

    if(result.success){

        closeModal('editAssignmentModal');

        loadAssignments();

    }

    } catch (error) {

        showFlashMessage("Unable to update assignment.", "error");

    }

}

async function loadEditTeachers(selectedId=""){

    try {

    const response = await fetch(
        "api/teacher_assignment.php?action=getTeachers"
    );

    const result = await response.json();

    const select =
    document.getElementById("editTeacher");

    select.innerHTML = "";

    result.data.forEach(teacher=>{

        select.innerHTML += `

            <option
                value="${teacher.user_id}"
                ${teacher.user_id==selectedId?"selected":""}>

                ${teacher.name}

            </option>

        `;

    });

    } catch (error) {

        showFlashMessage("Unable to load teachers.", "error");

    }

}

async function loadEditSubjects(selectedId=""){

    try {

    const response = await fetch(
        "api/teacher_assignment.php?action=getSubjects"
    );

    const result = await response.json();

    const select =
    document.getElementById("editSubject");

    select.innerHTML = "";

    result.data.forEach(subject=>{

        select.innerHTML += `

            <option
                value="${subject.subject_id}"
                ${subject.subject_id==selectedId?"selected":""}>

                ${subject.subject_name}

            </option>

        `;

    });

    } catch (error) {

        showFlashMessage("Unable to load subjects.", "error");

    }

}

async function loadEditClasses(selectedId=""){

    try {

    const response = await fetch(
        "api/teacher_assignment.php?action=getClasses"
    );

    const result = await response.json();

    const select =
    document.getElementById("editClass");

    select.innerHTML = "";

    result.data.forEach(classroom=>{

        select.innerHTML += `

            <option
                value="${classroom.class_id}"
                ${classroom.class_id==selectedId?"selected":""}>

                ${classroom.class_name}

            </option>

        `;

    });

    } catch (error) {

        showFlashMessage("Unable to load classes.", "error");

    }

}

loadTeachers();

loadSubjects();

loadClasses();

loadAssignments();
